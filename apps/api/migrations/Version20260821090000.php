<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #2942 — atrybut `name` dla tenantów założonych bez niego.
 *
 * `ObjectAttributesUpserter` po cichu pomija klucze payloadu, dla których
 * w tenancie nie ma atrybutu o takim kodzie (świadoma decyzja z #45 — ścieżka
 * importu na tym polega). Atrybut `name` powstawał wyłącznie w
 * `DemoCatalogSeeder`, więc tenant założony z panelu platformy dostawał
 * ObjectType'y i atrybuty systemowe, ale nie `name`. Każde
 * `POST /api/categories` z `attributes: {name: "Książki"}` zapisywało wtedy
 * NIC, `attributes_indexed` zostawało puste, a drzewo kategorii spadało na
 * `code` — operator widział „ksiazki" zamiast nazwy, którą wpisał.
 *
 * Migracja robi to samo, co `BuiltInLabelAttributeSeeder` robi od teraz dla
 * nowych tenantów:
 *
 * 1. Wstawia brakujący `name` (text, tłumaczalny, NIEwymagany) dla każdego
 *    tenanta. `is_system=false` — to etykieta operatora, ma być widoczna
 *    i edytowalna w bibliotece atrybutów, inaczej niż atrybuty audytowe.
 *    Brak `is_required` jest celowy: instalacja działająca od wczoraj nie
 *    może nagle zacząć odrzucać zapisów bez nazwy.
 *
 * 2. Ustawia `label_attribute_id` na wbudowanych ObjectType'ach
 *    (product / category / asset), które go nie mają — z tego wskaźnika
 *    korzystają `GetObjectSummaryHandler` i eksport kategorii.
 *
 * Nie tworzy AttributeGroup ani wiersza w `object_type_attributes`:
 * widoczność na formularzu zostaje jawną decyzją modelowania, dokładnie jak
 * przy atrybutach systemowych.
 *
 * Istniejące kategorie nie dostają wartości — nazwy nie da się zgadnąć
 * z kodu. Operator nadaje je z panelu; teraz zapis wreszcie dochodzi.
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the `name` label attribute for tenants provisioned without it (#2942)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO attributes (
                id, tenant_id, code, label, type,
                is_localizable, is_scopable, is_required, is_system, is_filterable,
                validation_rules, position, created_at
            )
            SELECT gen_random_uuid(), t.id, 'name',
                   '{"pl":"Nazwa","en":"Name"}'::jsonb, 'text',
                   true, false, false, false, false,
                   '{"max_length":255}'::jsonb, 0, NOW()
            FROM tenants t
            WHERE NOT EXISTS (
                SELECT 1 FROM attributes a
                WHERE a.tenant_id = t.id AND a.code = 'name'
            )
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE object_types ot
               SET label_attribute_id = a.id,
                   updated_at = NOW()
              FROM attributes a
             WHERE a.tenant_id = ot.tenant_id
               AND a.code = 'name'
               AND ot.label_attribute_id IS NULL
               AND ot.is_built_in = true
               AND ot.kind IN ('product', 'category', 'asset')
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nie kasujemy atrybutu `name` — w tenancie, który zdążył zapisać pod
        // nim wartości, DELETE zabrałby dane (a FK z `object_values` i tak by
        // go zatrzymał). Cofamy wyłącznie wskaźnik etykiety, i tylko tam,
        // gdzie nadal wskazuje na `name`.
        $this->addSql(<<<'SQL'
            UPDATE object_types ot
               SET label_attribute_id = NULL
              FROM attributes a
             WHERE a.id = ot.label_attribute_id
               AND a.code = 'name'
               AND ot.is_built_in = true
               AND ot.kind IN ('product', 'category', 'asset')
            SQL);
    }
}
