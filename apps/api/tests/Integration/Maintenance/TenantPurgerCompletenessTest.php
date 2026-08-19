<?php

declare(strict_types=1);

namespace App\Tests\Integration\Maintenance;

use App\Shared\Infrastructure\Maintenance\TenantPurger;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #2956 — bramka na KOMPLETNOŚĆ listy tabel w {@see TenantPurger}.
 *
 * Bramka istniała wcześniej i była bezużyteczna z jednego powodu:
 * `TenantOffboardingPurgeTest` sprawdzał listę `TENANT_TABLES`, która jest
 * **kopią** listy z purgera. Test potwierdzał więc, że purger kasuje to, co
 * purger zna — a nie to, co istnieje. Nowa tabela z `tenant_id` nie trafiała
 * ani do jednego, ani do drugiego i nic się nie zapalało.
 *
 * Tak przez pięć epików (agent, integracje, workflow, feedy, katalogi PDF)
 * uzbierało się **27 tabel spoza listy**: purger znał 46 z 69. Skutkiem nie
 * było „niedoczyszczenie", tylko `pim:tenants:purge-deleted` **niezdolny
 * usunąć jakiegokolwiek tenanta, który cokolwiek robił w systemie** — bo
 * pierwszy klucz obcy wywracał całą transakcję. Niewykonany obowiązek z art.
 * 17 RODO, w codziennym zadaniu, o 03:00, bez nikogo przy klawiaturze.
 *
 * Ten test czyta listę ze ŹRÓDŁA — z katalogu systemowego bazy — więc nie da
 * się go „zaktualizować razem z kodem". Nowa tabela z `tenant_id` czerwieni go
 * do momentu, w którym ktoś świadomie zdecyduje, gdzie w kolejności kasowania
 * ją wstawić. To jedyny wariant, w którym ten defekt nie wraca przy kolejnym
 * epiku.
 */
final class TenantPurgerCompletenessTest extends KernelTestCase
{
    use ResetDatabase;

    /**
     * Tabele z kolumną `tenant_id`, których purger celowo NIE kasuje.
     *
     * Każdy wpis wymaga uzasadnienia — jeśli nie potrafisz go napisać, tabela
     * należy do `DELETE_ORDER`, nie tutaj.
     *
     * @var array<string, string>
     */
    private const array SWIADOME_POMINIECIA = [
        // Kasowany osobno, PO wszystkich zależnościach — to jest wiersz,
        // do którego one wszystkie się odwołują.
        'tenants' => 'sam tenant, usuwany na końcu poza pętlą',
    ];

    #[Test]
    public function purgerZnaKazdaTabeleZKolumnaTenantId(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        /** @var list<string> $wBazie */
        $wBazie = $connection->fetchFirstColumn(
            // tenant-safe: odczyt katalogu systemowego Postgresa, bez danych tenantów.
            "SELECT table_name FROM information_schema.columns
              WHERE table_schema = 'public' AND column_name = 'tenant_id'
              ORDER BY table_name",
        );

        $znanePurgerowi = self::tabeleZPurgera();
        $brakujace = array_values(array_diff(
            $wBazie,
            $znanePurgerowi,
            array_keys(self::SWIADOME_POMINIECIA),
        ));

        self::assertSame([], $brakujace, \sprintf(
            "Tabele z kolumną `tenant_id` nieznane TenantPurgerowi:\n  %s\n\n".
            'Twarde usunięcie tenanta wywróci się na pierwszym kluczu obcym do tych tabel, '.
            "a `pim:tenants:purge-deleted` przestanie usuwać KOGOKOLWIEK.\n".
            'Dopisz je do DELETE_ORDER w kolejności dzieci-przed-rodzicami albo — jeśli mają '.
            'zostać po usunięciu tenanta — do SWIADOME_POMINIECIA razem z powodem.',
            implode("\n  ", $brakujace),
        ));
    }

    /**
     * Lista faktycznie używana przy kasowaniu, czytana z prywatnej stałej —
     * celowo, żeby test nie miał własnej kopii, którą trzeba synchronizować.
     *
     * @return list<string>
     */
    private static function tabeleZPurgera(): array
    {
        $stala = new ReflectionClass(TenantPurger::class)->getConstant('DELETE_ORDER');
        \assert(\is_array($stala));

        $tabele = [];
        foreach ($stala as $wpis) {
            \assert(\is_array($wpis));
            $nazwa = $wpis[0];
            \assert(\is_string($nazwa));
            $tabele[] = $nazwa;
        }

        return $tabele;
    }
}
