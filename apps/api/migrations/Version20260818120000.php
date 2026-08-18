<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TNT-P4-03 (#2904) — subdomena i stany cyklu życia instancji tenanta.
 *
 * Epik TNT (ADR-0035) daje każdemu tenantowi własną instancję, a ADR-0036
 * przenosi zakładanie klientów do panelu platformy. Panel musi umieć pokazać,
 * że instancja jest w trakcie powstawania albo że jej tworzenie się nie
 * powiodło — dotąd tabela znała wyłącznie stany `active`, `suspended`
 * i `deleted`, więc „w trakcie" nie dawało się zapisać.
 *
 * Dwie zmiany:
 *
 * 1. Rozszerzenie `tenants_status_check` o `pending`, `provisioning`
 *    i `failed`. Stany istniejące zostają nietknięte, więc migracja jest
 *    bezpieczna dla działających instalacji.
 *
 * 2. Unikalny indeks częściowy na `domain`. Subdomena adresuje instancję, więc
 *    dwa tenanty pod tym samym adresem to nie „duplikat danych", tylko dwie
 *    instancje walczące o ten sam host. Indeks jest CZĘŚCIOWY (`WHERE domain
 *    IS NOT NULL`), bo instalacje jednotenantowe i tenant platformowy mogą nie
 *    mieć subdomeny — a `NULL` w unikalnym indeksie nie kolidowałby ze sobą
 *    tylko przypadkiem, nie z projektu.
 */
final class Version20260818120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tenant lifecycle states for provisioning + unique subdomain';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
                CHECK (status::text = ANY (ARRAY[
                    'pending'::text,
                    'provisioning'::text,
                    'active'::text,
                    'failed'::text,
                    'suspended'::text,
                    'deleted'::text
                ]))
            SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS tenants_domain_uniq
                ON tenants (domain)
                WHERE domain IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS tenants_domain_uniq');

        // Powrót do wąskiego zestawu stanów wymaga sprowadzenia wierszy
        // pośrednich do stanu, który stary CHECK dopuszcza. `pending`
        // i `provisioning` to instancje jeszcze niegotowe, `failed` to
        // instancja, której nie udało się postawić — wszystkie trzy najbliżej
        // odpowiadają „zawieszonej", a nie „aktywnej": nie wolno ich pokazać
        // jako działających.
        $this->addSql(<<<'SQL'
            UPDATE tenants SET status = 'suspended'
             WHERE status IN ('pending', 'provisioning', 'failed')
            SQL);

        $this->addSql('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE tenants ADD CONSTRAINT tenants_status_check
                CHECK (status::text = ANY (ARRAY[
                    'active'::text,
                    'suspended'::text,
                    'deleted'::text
                ]))
            SQL);
    }
}
