<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WFL-P2-02 (#2421) — `notifications`: persistent per-user in-app
 * notifications (bell). Generic infra (type + payload JSONB) so future
 * modules reuse the same rows; the workflow fan-out is the first
 * writer. Tenant RLS + Super Admin bypass — same pattern as
 * workflow_transitions (Version20260711090000).
 */
final class Version20260711110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WFL-P2-02: notifications table + tenant RLS policies';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
                id UUID NOT NULL,
                tenant_id UUID NOT NULL,
                user_id UUID NOT NULL,
                type VARCHAR(64) NOT NULL,
                payload JSONB DEFAULT NULL,
                read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX notifications_tenant_user_read_idx ON notifications (tenant_id, user_id, read_at)');
        $this->addSql('CREATE INDEX notifications_tenant_user_id_idx ON notifications (tenant_id, user_id, id)');
        $this->addSql(
            'ALTER TABLE notifications ADD CONSTRAINT fk_notifications_tenant '
            .'FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE'
        );
        $this->addSql(
            'ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user '
            .'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
        );

        $predicate = "tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid";
        $this->addSql('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE notifications FORCE ROW LEVEL SECURITY');
        $this->addSql(\sprintf(
            'CREATE POLICY tenant_isolation_notifications ON notifications USING (%s) WITH CHECK (%s)',
            $predicate,
            $predicate,
        ));
        $this->addSql(
            'CREATE POLICY super_admin_bypass_notifications ON notifications '
            ."USING (current_setting('app.is_super_admin', true) = 'true') "
            ."WITH CHECK (current_setting('app.is_super_admin', true) = 'true')"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP POLICY IF EXISTS super_admin_bypass_notifications ON notifications');
        $this->addSql('DROP POLICY IF EXISTS tenant_isolation_notifications ON notifications');
        $this->addSql('ALTER TABLE notifications NO FORCE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE notifications DISABLE ROW LEVEL SECURITY');
        $this->addSql('DROP TABLE notifications');
    }
}
