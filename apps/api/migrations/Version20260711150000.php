<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2491 — heal the role_attribute_permissions schema drift.
 *
 * Version20260518160000 created the table with a composite PK
 * (role_id, attribute_id) and no `id` column; the later
 * Version20260520090000 (#697) used CREATE TABLE IF NOT EXISTS with an
 * `id UUID PRIMARY KEY`, which was a silent no-op on databases where the
 * earlier migration had already created the table. The ORM mapping (and
 * therefore the test databases, built from mappings) expects `id` — so
 * every ORM read/write on the entity failed with 42703 on
 * migration-built databases while CI stayed green.
 *
 * Converge migration-built schemas to the mapping shape: surrogate
 * `id` PK, (role_id, attribute_id) kept unique. Defensive — a no-op on
 * databases that already carry the column.
 */
final class Version20260711150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing id PK column to role_attribute_permissions (drift vs ORM mapping)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public'
                      AND table_name = 'role_attribute_permissions'
                      AND column_name = 'id'
                ) THEN
                    ALTER TABLE role_attribute_permissions ADD COLUMN id UUID;
                    UPDATE role_attribute_permissions SET id = gen_random_uuid() WHERE id IS NULL;
                    ALTER TABLE role_attribute_permissions ALTER COLUMN id SET NOT NULL;
                    ALTER TABLE role_attribute_permissions DROP CONSTRAINT role_attribute_permissions_pkey;
                    ALTER TABLE role_attribute_permissions ADD PRIMARY KEY (id);
                END IF;
            END
            $$;
        SQL);

        // The unique guarantee the composite PK used to provide. Both
        // migration variants already create it, but keep the invariant
        // explicit in case of hand-healed databases.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS role_attribute_permissions_role_attr_uniq
                ON role_attribute_permissions (role_id, attribute_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_attribute_permissions DROP CONSTRAINT role_attribute_permissions_pkey');
        $this->addSql('ALTER TABLE role_attribute_permissions DROP COLUMN id');
        $this->addSql('ALTER TABLE role_attribute_permissions ADD PRIMARY KEY (role_id, attribute_id)');
    }
}
