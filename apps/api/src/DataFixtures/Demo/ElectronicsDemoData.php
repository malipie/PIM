<?php

declare(strict_types=1);

namespace App\DataFixtures\Demo;

use App\Catalog\Domain\AttributeType;

/**
 * Static data catalog for {@see ElectronicsDemoSeeder}: category trees,
 * attribute + attribute-group definitions, service definitions and the
 * name-variant color words. Pure data, no behavior — split out so the
 * seeder file stays under the API max-lines guard.
 */
final class ElectronicsDemoData
{
    /** @var array{0: string, 1: string}[] */
    public const array COLOR_WORDS = [
        ['czarny', 'Black'],
        ['biały', 'White'],
        ['srebrny', 'Silver'],
        ['niebieski', 'Blue'],
        ['grafitowy', 'Graphite'],
    ];

    /**
     * Product category tree. Key = ltree segment (ASCII). `count` is the
     * weight used to distribute products; 0 = structural node only.
     *
     * @var array<string, array{label: array{pl: string, en: string}, parent: ?string, count: int, hue: int, price: array{0: int, 1: int}, weight: array{0: float, 1: float}, models: list<string>, specs: list<string>, screen?: array{0: float, 1: float}, os?: string}>
     */
    public const array PRODUCT_CATEGORIES = [
        'smartfony' => ['label' => ['pl' => 'Smartfony', 'en' => 'Smartphones'], 'parent' => null, 'count' => 120, 'hue' => 210, 'price' => [899, 6999], 'weight' => [0.15, 0.25], 'specs' => ['display', 'computing', 'mobile'], 'screen' => [6.1, 6.9], 'os' => 'android', 'models' => ['Samsung Galaxy S24', 'Samsung Galaxy A55', 'Apple iPhone 15', 'Apple iPhone 14', 'Xiaomi Redmi Note 13', 'Google Pixel 8', 'OnePlus 12', 'Motorola Edge 50']],
        'laptopy' => ['label' => ['pl' => 'Laptopy', 'en' => 'Laptops'], 'parent' => null, 'count' => 110, 'hue' => 260, 'price' => [1999, 12999], 'weight' => [1.1, 2.8], 'specs' => ['display', 'computing'], 'screen' => [13.0, 17.0], 'os' => 'windows', 'models' => ['Lenovo ThinkPad T14', 'Lenovo IdeaPad 5', 'Dell XPS 13', 'Dell Inspiron 16', 'HP EliteBook 840', 'HP Pavilion 15', 'Apple MacBook Air M3', 'Asus ZenBook 14', 'Acer Aspire 5']],
        'tablety' => ['label' => ['pl' => 'Tablety', 'en' => 'Tablets'], 'parent' => null, 'count' => 60, 'hue' => 190, 'price' => [599, 5499], 'weight' => [0.3, 0.7], 'specs' => ['display', 'computing', 'mobile'], 'screen' => [8.0, 13.0], 'os' => 'android', 'models' => ['Samsung Galaxy Tab S9', 'Apple iPad Air', 'Apple iPad 10', 'Lenovo Tab P12', 'Xiaomi Pad 6']],
        'telewizory' => ['label' => ['pl' => 'Telewizory', 'en' => 'TVs'], 'parent' => null, 'count' => 90, 'hue' => 0, 'price' => [1299, 14999], 'weight' => [8.0, 35.0], 'specs' => ['display'], 'screen' => [43.0, 85.0], 'models' => ['Samsung QLED Q80D', 'LG OLED C4', 'Sony Bravia X90L', 'TCL C745', 'Philips Ambilight OLED808']],
        'monitory' => ['label' => ['pl' => 'Monitory', 'en' => 'Monitors'], 'parent' => null, 'count' => 70, 'hue' => 30, 'price' => [499, 4999], 'weight' => [3.0, 9.0], 'specs' => ['display'], 'screen' => [24.0, 34.0], 'models' => ['Dell UltraSharp U2723QE', 'LG UltraGear 27GP850', 'Samsung Odyssey G7', 'iiyama ProLite XUB2792', 'BenQ PD2705U']],
        'audio' => ['label' => ['pl' => 'Audio', 'en' => 'Audio'], 'parent' => null, 'count' => 0, 'hue' => 120, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'sluchawki' => ['label' => ['pl' => 'Słuchawki', 'en' => 'Headphones'], 'parent' => 'audio', 'count' => 80, 'hue' => 130, 'price' => [99, 1899], 'weight' => [0.05, 0.4], 'specs' => ['audio'], 'models' => ['Sony WH-1000XM5', 'Bose QuietComfort 45', 'Apple AirPods Pro 2', 'JBL Tune 770NC', 'Sennheiser Momentum 4']],
        'glosniki' => ['label' => ['pl' => 'Głośniki', 'en' => 'Speakers'], 'parent' => 'audio', 'count' => 60, 'hue' => 150, 'price' => [149, 2999], 'weight' => [0.5, 5.0], 'specs' => ['audio'], 'models' => ['JBL Charge 5', 'Sonos One SL', 'Marshall Stanmore III', 'Bose SoundLink Flex', 'Tribit StormBox Blast']],
        'foto' => ['label' => ['pl' => 'Fotografia', 'en' => 'Photography'], 'parent' => null, 'count' => 0, 'hue' => 280, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'aparaty' => ['label' => ['pl' => 'Aparaty', 'en' => 'Cameras'], 'parent' => 'foto', 'count' => 60, 'hue' => 290, 'price' => [1999, 15999], 'weight' => [0.3, 1.2], 'specs' => ['photo'], 'models' => ['Canon EOS R50', 'Nikon Z50', 'Sony A6400', 'Fujifilm X-T30 II', 'Panasonic Lumix G100']],
        'gaming' => ['label' => ['pl' => 'Gaming', 'en' => 'Gaming'], 'parent' => null, 'count' => 0, 'hue' => 330, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'konsole' => ['label' => ['pl' => 'Konsole', 'en' => 'Consoles'], 'parent' => 'gaming', 'count' => 50, 'hue' => 340, 'price' => [999, 3499], 'weight' => [1.5, 4.5], 'specs' => ['computing'], 'os' => 'none', 'models' => ['Sony PlayStation 5', 'Sony PlayStation 5 Digital', 'Microsoft Xbox Series X', 'Microsoft Xbox Series S', 'Nintendo Switch OLED']],
        'smartwatche' => ['label' => ['pl' => 'Smartwatche', 'en' => 'Smartwatches'], 'parent' => null, 'count' => 70, 'hue' => 50, 'price' => [299, 3999], 'weight' => [0.03, 0.09], 'specs' => ['display', 'mobile'], 'screen' => [1.2, 2.0], 'models' => ['Apple Watch Series 9', 'Samsung Galaxy Watch 6', 'Garmin Venu 3', 'Huawei Watch GT 4', 'Amazfit GTR 4']],
        'agd' => ['label' => ['pl' => 'AGD', 'en' => 'Home appliances'], 'parent' => null, 'count' => 0, 'hue' => 90, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'lodowki' => ['label' => ['pl' => 'Lodówki', 'en' => 'Refrigerators'], 'parent' => 'agd', 'count' => 50, 'hue' => 95, 'price' => [1499, 8999], 'weight' => [55.0, 120.0], 'specs' => ['appliance'], 'models' => ['Samsung RB38C', 'Bosch KGN39', 'LG GBB72', 'Whirlpool W7X 82', 'Beko B5RCNA405']],
        'pralki' => ['label' => ['pl' => 'Pralki', 'en' => 'Washing machines'], 'parent' => 'agd', 'count' => 50, 'hue' => 100, 'price' => [1199, 4999], 'weight' => [60.0, 85.0], 'specs' => ['appliance'], 'models' => ['Bosch WAN2822', 'Samsung WW90', 'LG F4WV329', 'Whirlpool FFB 8258', 'Electrolux EW6F348']],
        'odkurzacze' => ['label' => ['pl' => 'Odkurzacze', 'en' => 'Vacuum cleaners'], 'parent' => 'agd', 'count' => 50, 'hue' => 110, 'price' => [299, 3599], 'weight' => [1.5, 8.0], 'specs' => ['appliance'], 'models' => ['Dyson V15 Detect', 'Xiaomi G10 Plus', 'Tefal X-Force Flex', 'Philips SpeedPro Max', 'Bosch Unlimited 7']],
        'akcesoria' => ['label' => ['pl' => 'Akcesoria', 'en' => 'Accessories'], 'parent' => null, 'count' => 50, 'hue' => 20, 'price' => [29, 899], 'weight' => [0.05, 1.0], 'specs' => [], 'models' => ['Logitech MX Master 3S', 'Keychron K8 Pro', 'Anker PowerCore 20000', 'Belkin BoostCharge 65W', 'SanDisk Ultra 128GB', 'Baseus Hub USB-C 8w1']],
        'siec' => ['label' => ['pl' => 'Sieć i internet', 'en' => 'Networking'], 'parent' => null, 'count' => 0, 'hue' => 170, 'price' => [0, 0], 'weight' => [0.0, 0.0], 'specs' => [], 'models' => []],
        'routery' => ['label' => ['pl' => 'Routery', 'en' => 'Routers'], 'parent' => 'siec', 'count' => 30, 'hue' => 175, 'price' => [149, 1999], 'weight' => [0.3, 1.2], 'specs' => ['network'], 'models' => ['TP-Link Archer AX55', 'Asus RT-AX58U', 'Netgear Nighthawk RAX43', 'Xiaomi AX3200', 'Ubiquiti UniFi Dream Router']],
    ];

    /**
     * @var array<string, array{label: array{pl: string, en: string}}>
     */
    public const array SERVICE_CATEGORIES = [
        'srv_montaz' => ['label' => ['pl' => 'Montaż i instalacja', 'en' => 'Installation']],
        'srv_serwis' => ['label' => ['pl' => 'Serwis i naprawa', 'en' => 'Repair services']],
        'srv_konfiguracja' => ['label' => ['pl' => 'Konfiguracja i wdrożenie', 'en' => 'Setup & onboarding']],
        'srv_gwarancje' => ['label' => ['pl' => 'Gwarancje rozszerzone', 'en' => 'Extended warranties']],
        'srv_szkolenia' => ['label' => ['pl' => 'Szkolenia', 'en' => 'Trainings']],
    ];

    /**
     * @var list<array{category: string, name: array{pl: string, en: string}, description: array{pl: string, en: string}, price: float, type: string, duration: float, sla: int, parts: bool}>
     */
    public const array SERVICES = [
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Montaż telewizora na ścianie', 'en' => 'Wall-mount TV installation'], 'description' => ['pl' => 'Profesjonalny montaż telewizora do 85" wraz z uchwytem i maskowaniem kabli.', 'en' => 'Professional wall mounting of TVs up to 85", bracket and cable management included.'], 'price' => 249.0, 'type' => 'onsite', 'duration' => 2.0, 'sla' => 3, 'parts' => true],
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Instalacja sprzętu AGD', 'en' => 'Home appliance installation'], 'description' => ['pl' => 'Podłączenie pralki, zmywarki lub lodówki wraz z testem szczelności.', 'en' => 'Connection of a washing machine, dishwasher or fridge with a leak test.'], 'price' => 179.0, 'type' => 'onsite', 'duration' => 1.5, 'sla' => 3, 'parts' => false],
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Montaż sieci domowej', 'en' => 'Home network installation'], 'description' => ['pl' => 'Rozprowadzenie okablowania i konfiguracja routera oraz access pointów.', 'en' => 'Cabling plus router and access point configuration.'], 'price' => 399.0, 'type' => 'onsite', 'duration' => 4.0, 'sla' => 5, 'parts' => true],
        ['category' => 'srv_montaz', 'name' => ['pl' => 'Instalacja soundbaru', 'en' => 'Soundbar installation'], 'description' => ['pl' => 'Montaż i kalibracja soundbaru z subwooferem.', 'en' => 'Mounting and calibration of a soundbar with subwoofer.'], 'price' => 149.0, 'type' => 'onsite', 'duration' => 1.0, 'sla' => 3, 'parts' => false],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Diagnoza laptopa', 'en' => 'Laptop diagnostics'], 'description' => ['pl' => 'Pełna diagnostyka sprzętowa i programowa laptopa z raportem.', 'en' => 'Full hardware and software laptop diagnostics with a report.'], 'price' => 99.0, 'type' => 'workshop', 'duration' => 1.0, 'sla' => 2, 'parts' => false],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Wymiana ekranu smartfona', 'en' => 'Smartphone screen replacement'], 'description' => ['pl' => 'Wymiana wyświetlacza z użyciem oryginalnych części.', 'en' => 'Display replacement using original parts.'], 'price' => 449.0, 'type' => 'workshop', 'duration' => 1.5, 'sla' => 2, 'parts' => true],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Wymiana baterii smartfona', 'en' => 'Smartphone battery replacement'], 'description' => ['pl' => 'Wymiana ogniwa wraz z kalibracją i testem pojemności.', 'en' => 'Battery swap with calibration and capacity test.'], 'price' => 199.0, 'type' => 'workshop', 'duration' => 1.0, 'sla' => 2, 'parts' => true],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Czyszczenie i konserwacja laptopa', 'en' => 'Laptop cleaning & maintenance'], 'description' => ['pl' => 'Czyszczenie układu chłodzenia, wymiana pasty termoprzewodzącej.', 'en' => 'Cooling system cleaning and thermal paste replacement.'], 'price' => 149.0, 'type' => 'workshop', 'duration' => 1.5, 'sla' => 3, 'parts' => true],
        ['category' => 'srv_serwis', 'name' => ['pl' => 'Serwis ekspresowy AGD', 'en' => 'Express appliance repair'], 'description' => ['pl' => 'Naprawa sprzętu AGD u klienta w 48 godzin.', 'en' => 'On-site appliance repair within 48 hours.'], 'price' => 299.0, 'type' => 'onsite', 'duration' => 2.0, 'sla' => 2, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Konfiguracja nowego komputera', 'en' => 'New PC setup'], 'description' => ['pl' => 'Instalacja systemu, sterowników i pakietu aplikacji startowych.', 'en' => 'OS, driver and starter app installation.'], 'price' => 149.0, 'type' => 'remote', 'duration' => 2.0, 'sla' => 1, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Konfiguracja routera i Wi-Fi', 'en' => 'Router & Wi-Fi setup'], 'description' => ['pl' => 'Bezpieczna konfiguracja sieci domowej z optymalizacją zasięgu.', 'en' => 'Secure home network setup with coverage optimisation.'], 'price' => 119.0, 'type' => 'remote', 'duration' => 1.0, 'sla' => 1, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Przeniesienie danych na nowy telefon', 'en' => 'Phone data migration'], 'description' => ['pl' => 'Migracja kontaktów, zdjęć i aplikacji między urządzeniami.', 'en' => 'Migration of contacts, photos and apps between devices.'], 'price' => 89.0, 'type' => 'workshop', 'duration' => 1.0, 'sla' => 1, 'parts' => false],
        ['category' => 'srv_konfiguracja', 'name' => ['pl' => 'Konfiguracja smart home', 'en' => 'Smart home setup'], 'description' => ['pl' => 'Integracja urządzeń smart home z aplikacją i automatyzacjami.', 'en' => 'Smart home device integration with app and automations.'], 'price' => 349.0, 'type' => 'onsite', 'duration' => 3.0, 'sla' => 5, 'parts' => false],
        ['category' => 'srv_gwarancje', 'name' => ['pl' => 'Gwarancja rozszerzona +12 miesięcy', 'en' => 'Extended warranty +12 months'], 'description' => ['pl' => 'Dodatkowy rok ochrony serwisowej po gwarancji producenta.', 'en' => 'An extra year of coverage after the manufacturer warranty.'], 'price' => 199.0, 'type' => 'remote', 'duration' => 0.5, 'sla' => 30, 'parts' => false],
        ['category' => 'srv_gwarancje', 'name' => ['pl' => 'Gwarancja rozszerzona +24 miesiące', 'en' => 'Extended warranty +24 months'], 'description' => ['pl' => 'Dwa dodatkowe lata ochrony serwisowej i door-to-door.', 'en' => 'Two extra years of coverage with door-to-door service.'], 'price' => 349.0, 'type' => 'remote', 'duration' => 0.5, 'sla' => 30, 'parts' => false],
        ['category' => 'srv_gwarancje', 'name' => ['pl' => 'Ochrona przed przypadkowym uszkodzeniem', 'en' => 'Accidental damage protection'], 'description' => ['pl' => 'Roczna ochrona obejmująca zalanie i upadki urządzenia.', 'en' => 'One-year protection covering liquid damage and drops.'], 'price' => 299.0, 'type' => 'remote', 'duration' => 0.5, 'sla' => 30, 'parts' => true],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Szkolenie z obsługi smartfona', 'en' => 'Smartphone basics training'], 'description' => ['pl' => 'Indywidualne szkolenie z podstaw obsługi smartfona dla seniorów.', 'en' => 'One-on-one smartphone basics training for seniors.'], 'price' => 129.0, 'type' => 'onsite', 'duration' => 2.0, 'sla' => 7, 'parts' => false],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Warsztaty fotograficzne', 'en' => 'Photography workshop'], 'description' => ['pl' => 'Warsztaty z obsługi aparatu bezlusterkowego i podstaw kompozycji.', 'en' => 'Mirrorless camera handling and composition basics workshop.'], 'price' => 249.0, 'type' => 'workshop', 'duration' => 4.0, 'sla' => 14, 'parts' => false],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Szkolenie ze smart home', 'en' => 'Smart home training'], 'description' => ['pl' => 'Praktyczne szkolenie z automatyzacji domu i scen świetlnych.', 'en' => 'Hands-on home automation and lighting scene training.'], 'price' => 199.0, 'type' => 'remote', 'duration' => 2.0, 'sla' => 7, 'parts' => false],
        ['category' => 'srv_szkolenia', 'name' => ['pl' => 'Szkolenie z bezpieczeństwa w sieci', 'en' => 'Online safety training'], 'description' => ['pl' => 'Szkolenie z haseł, menedżerów haseł i rozpoznawania phishingu.', 'en' => 'Passwords, password managers and phishing awareness training.'], 'price' => 159.0, 'type' => 'remote', 'duration' => 1.5, 'sla' => 7, 'parts' => false],
    ];

    /**
     * @return array<string, array{label: array{pl: string, en: string}, icon: ?string, color: ?string, required_section?: bool, attributes: list<string>}>
     */
    public static function groupDefinitions(): array
    {
        return [
            'basic' => ['label' => ['pl' => 'Dane podstawowe', 'en' => 'Basic data'], 'icon' => 'Info', 'color' => '#3B82F6', 'required_section' => true, 'attributes' => ['name', 'sku', 'ean', 'brand', 'vat_rate', 'short_description', 'description']],
            'pricing' => ['label' => ['pl' => 'Ceny', 'en' => 'Pricing'], 'icon' => 'Banknote', 'color' => '#10B981', 'attributes' => ['price']],
            'media' => ['label' => ['pl' => 'Multimedia', 'en' => 'Media'], 'icon' => 'Image', 'color' => '#8B5CF6', 'attributes' => ['main_image']],
            'logistics' => ['label' => ['pl' => 'Logistyka i stan', 'en' => 'Logistics & stock'], 'icon' => 'Truck', 'color' => '#F97316', 'attributes' => ['weight', 'in_stock', 'warranty_months']],
            'marketing' => ['label' => ['pl' => 'Marketing', 'en' => 'Marketing'], 'icon' => 'Megaphone', 'color' => '#EC4899', 'attributes' => ['description_html', 'tags', 'release_date', 'eol_date']],
            'display_specs' => ['label' => ['pl' => 'Ekran', 'en' => 'Display'], 'icon' => 'Monitor', 'color' => '#0EA5E9', 'attributes' => ['screen_size', 'resolution', 'refresh_rate', 'panel_type']],
            'computing_specs' => ['label' => ['pl' => 'Podzespoły', 'en' => 'Computing'], 'icon' => 'Cpu', 'color' => '#6366F1', 'attributes' => ['cpu', 'ram', 'storage', 'os']],
            'mobile_specs' => ['label' => ['pl' => 'Parametry mobilne', 'en' => 'Mobile specs'], 'icon' => 'Smartphone', 'color' => '#14B8A6', 'attributes' => ['battery_capacity', 'camera_mp', 'ip_rating', 'connectivity']],
            'audio_specs' => ['label' => ['pl' => 'Parametry audio', 'en' => 'Audio specs'], 'icon' => 'Volume2', 'color' => '#22C55E', 'attributes' => ['connectivity', 'power_w', 'noise_level']],
            'photo_specs' => ['label' => ['pl' => 'Parametry foto', 'en' => 'Photo specs'], 'icon' => 'Camera', 'color' => '#A855F7', 'attributes' => ['camera_mp', 'sensor_type', 'optical_zoom']],
            'appliance_specs' => ['label' => ['pl' => 'Parametry AGD', 'en' => 'Appliance specs'], 'icon' => 'Plug', 'color' => '#84CC16', 'attributes' => ['energy_class', 'capacity_l', 'spin_speed', 'noise_level', 'power_w', 'voltage']],
            'network_specs' => ['label' => ['pl' => 'Parametry sieciowe', 'en' => 'Network specs'], 'icon' => 'Wifi', 'color' => '#06B6D4', 'attributes' => ['wifi_standard', 'lan_ports', 'connectivity']],
            'service_basic' => ['label' => ['pl' => 'Dane usługi', 'en' => 'Service data'], 'icon' => 'Info', 'color' => '#F59E0B', 'required_section' => true, 'attributes' => ['name', 'sku', 'description', 'price', 'vat_rate']],
            'service_params' => ['label' => ['pl' => 'Parametry usługi', 'en' => 'Service parameters'], 'icon' => 'Wrench', 'color' => '#F97316', 'attributes' => ['service_type', 'service_duration', 'sla_days', 'includes_parts', 'warranty_months']],
        ];
    }

    /**
     * Attribute catalog — codes already seeded by the base fixtures are
     * looked up first; definitions below only materialise when missing,
     * so the seeder also works on a non-fixtures database.
     *
     * @return array<string, array{label: array<string, string>, type: AttributeType, required?: bool, localizable?: bool, scopable?: bool, filterable?: bool, rules?: array<string, mixed>, options?: list<array{0: string, 1: array<string, string>, 2?: ?string, 3?: bool}>}>
     */
    public static function attributeDefinitions(): array
    {
        return [
            // ── Reused base attributes (fixtures usually provide these) ──
            'name' => ['label' => ['pl' => 'Nazwa', 'en' => 'Name'], 'type' => AttributeType::Text, 'required' => true, 'localizable' => true, 'rules' => ['max_length' => 255]],
            'sku' => ['label' => ['pl' => 'SKU', 'en' => 'SKU'], 'type' => AttributeType::Text, 'required' => true, 'rules' => ['pattern' => '/^[A-Z0-9-]+$/']],
            'description' => ['label' => ['pl' => 'Opis', 'en' => 'Description'], 'type' => AttributeType::Text, 'localizable' => true],
            'description_html' => ['label' => ['pl' => 'Opis (rich text)', 'en' => 'Description (rich text)'], 'type' => AttributeType::Wysiwyg, 'localizable' => true, 'rules' => ['max_length' => 50_000]],
            'short_description' => ['label' => ['pl' => 'Krótki opis', 'en' => 'Short description'], 'type' => AttributeType::Text, 'localizable' => true, 'scopable' => true, 'rules' => ['max_length' => 280]],
            'brand' => ['label' => ['pl' => 'Marka', 'en' => 'Brand'], 'type' => AttributeType::Text, 'filterable' => true],
            'tags' => ['label' => ['pl' => 'Tagi', 'en' => 'Tags'], 'type' => AttributeType::Multiselect, 'filterable' => true, 'rules' => ['max_count' => 5], 'options' => [['new', ['pl' => 'Nowość', 'en' => 'New']], ['sale', ['pl' => 'Promocja', 'en' => 'Sale']], ['eco', ['pl' => 'Eko', 'en' => 'Eco']], ['premium', ['pl' => 'Premium', 'en' => 'Premium']]]],
            'price' => ['label' => ['pl' => 'Cena', 'en' => 'Price'], 'type' => AttributeType::Price, 'scopable' => true, 'filterable' => true, 'rules' => ['min_amount' => 0, 'currencies' => ['PLN', 'EUR', 'USD']]],
            'weight' => ['label' => ['pl' => 'Waga', 'en' => 'Weight'], 'type' => AttributeType::Metric, 'filterable' => true, 'rules' => ['units' => ['kg', 'g'], 'min' => 0]],
            'in_stock' => ['label' => ['pl' => 'Na stanie', 'en' => 'In stock'], 'type' => AttributeType::Boolean, 'filterable' => true],
            'release_date' => ['label' => ['pl' => 'Data premiery', 'en' => 'Release date'], 'type' => AttributeType::Date, 'filterable' => true],
            'eol_date' => ['label' => ['pl' => 'Koniec wsparcia', 'en' => 'End of life'], 'type' => AttributeType::Date],
            'main_image' => ['label' => ['pl' => 'Zdjęcie główne', 'en' => 'Main image'], 'type' => AttributeType::Asset],
            'ip_rating' => ['label' => ['pl' => 'Klasa szczelności (IP)', 'en' => 'IP rating'], 'type' => AttributeType::Select, 'options' => [['IP54', ['pl' => 'IP54', 'en' => 'IP54']], ['IP67', ['pl' => 'IP67', 'en' => 'IP67']], ['IP68', ['pl' => 'IP68', 'en' => 'IP68']]]],
            'vat_rate' => ['label' => ['pl' => 'Stawka VAT', 'en' => 'VAT rate'], 'type' => AttributeType::Select, 'options' => [['vat_23', ['pl' => '23%', 'en' => '23%'], null, true], ['vat_8', ['pl' => '8%', 'en' => '8%']], ['vat_0', ['pl' => '0%', 'en' => '0%']]]],
            'warranty_months' => ['label' => ['pl' => 'Gwarancja (msc)', 'en' => 'Warranty (months)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 120]],
            'voltage' => ['label' => ['pl' => 'Napięcie', 'en' => 'Voltage'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['V'], 'min' => 0]],
            'power_w' => ['label' => ['pl' => 'Moc', 'en' => 'Power'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['W'], 'min' => 0]],

            // ── Electronics-specific additions ──
            'ean' => ['label' => ['pl' => 'EAN', 'en' => 'EAN'], 'type' => AttributeType::Text, 'filterable' => true, 'rules' => ['pattern' => '/^\d{13}$/']],
            'screen_size' => ['label' => ['pl' => 'Przekątna ekranu', 'en' => 'Screen size'], 'type' => AttributeType::Metric, 'filterable' => true, 'rules' => ['units' => ['in', 'cm'], 'min' => 0]],
            'resolution' => ['label' => ['pl' => 'Rozdzielczość', 'en' => 'Resolution'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['hd', ['pl' => 'HD (1366×768)', 'en' => 'HD (1366×768)']], ['fhd', ['pl' => 'Full HD (1920×1080)', 'en' => 'Full HD (1920×1080)']], ['qhd', ['pl' => 'QHD (2560×1440)', 'en' => 'QHD (2560×1440)']], ['uhd4k', ['pl' => '4K UHD (3840×2160)', 'en' => '4K UHD (3840×2160)']], ['uhd8k', ['pl' => '8K UHD (7680×4320)', 'en' => '8K UHD (7680×4320)']]]],
            'refresh_rate' => ['label' => ['pl' => 'Częstotliwość odświeżania (Hz)', 'en' => 'Refresh rate (Hz)'], 'type' => AttributeType::Number, 'filterable' => true, 'rules' => ['min' => 0, 'max' => 480]],
            'panel_type' => ['label' => ['pl' => 'Typ matrycy', 'en' => 'Panel type'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['ips', ['pl' => 'IPS', 'en' => 'IPS'], '#0EA5E9'], ['va', ['pl' => 'VA', 'en' => 'VA'], '#64748B'], ['oled', ['pl' => 'OLED', 'en' => 'OLED'], '#A855F7'], ['amoled', ['pl' => 'AMOLED', 'en' => 'AMOLED'], '#EC4899'], ['miniled', ['pl' => 'Mini-LED', 'en' => 'Mini-LED'], '#F59E0B']]],
            'cpu' => ['label' => ['pl' => 'Procesor', 'en' => 'CPU'], 'type' => AttributeType::Text, 'filterable' => true],
            'ram' => ['label' => ['pl' => 'Pamięć RAM', 'en' => 'RAM'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['4gb', ['pl' => '4 GB', 'en' => '4 GB']], ['8gb', ['pl' => '8 GB', 'en' => '8 GB']], ['16gb', ['pl' => '16 GB', 'en' => '16 GB']], ['32gb', ['pl' => '32 GB', 'en' => '32 GB']], ['64gb', ['pl' => '64 GB', 'en' => '64 GB']]]],
            'storage' => ['label' => ['pl' => 'Pamięć wbudowana', 'en' => 'Storage'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['128gb', ['pl' => '128 GB', 'en' => '128 GB']], ['256gb', ['pl' => '256 GB', 'en' => '256 GB']], ['512gb', ['pl' => '512 GB', 'en' => '512 GB']], ['1tb', ['pl' => '1 TB', 'en' => '1 TB']], ['2tb', ['pl' => '2 TB', 'en' => '2 TB']]]],
            'os' => ['label' => ['pl' => 'System operacyjny', 'en' => 'Operating system'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['android', ['pl' => 'Android', 'en' => 'Android']], ['ios', ['pl' => 'iOS / iPadOS', 'en' => 'iOS / iPadOS']], ['windows', ['pl' => 'Windows 11', 'en' => 'Windows 11']], ['macos', ['pl' => 'macOS', 'en' => 'macOS']], ['linux', ['pl' => 'Linux', 'en' => 'Linux']], ['none', ['pl' => 'Brak / dedykowany', 'en' => 'None / dedicated']]]],
            'battery_capacity' => ['label' => ['pl' => 'Pojemność baterii (mAh)', 'en' => 'Battery capacity (mAh)'], 'type' => AttributeType::Number, 'filterable' => true, 'rules' => ['min' => 0]],
            'camera_mp' => ['label' => ['pl' => 'Matryca aparatu (Mpix)', 'en' => 'Camera (MP)'], 'type' => AttributeType::Number, 'filterable' => true, 'rules' => ['min' => 0]],
            'connectivity' => ['label' => ['pl' => 'Łączność', 'en' => 'Connectivity'], 'type' => AttributeType::Multiselect, 'filterable' => true, 'options' => [['wifi', ['pl' => 'Wi-Fi', 'en' => 'Wi-Fi']], ['bt', ['pl' => 'Bluetooth', 'en' => 'Bluetooth']], ['nfc', ['pl' => 'NFC', 'en' => 'NFC']], ['g5', ['pl' => '5G', 'en' => '5G']], ['usb_c', ['pl' => 'USB-C', 'en' => 'USB-C']], ['hdmi', ['pl' => 'HDMI', 'en' => 'HDMI']], ['ethernet', ['pl' => 'Ethernet', 'en' => 'Ethernet']]]],
            'energy_class' => ['label' => ['pl' => 'Klasa energetyczna', 'en' => 'Energy class'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['a', ['pl' => 'A', 'en' => 'A'], '#22C55E'], ['b', ['pl' => 'B', 'en' => 'B'], '#84CC16'], ['c', ['pl' => 'C', 'en' => 'C'], '#EAB308'], ['d', ['pl' => 'D', 'en' => 'D'], '#F97316'], ['e', ['pl' => 'E', 'en' => 'E'], '#EF4444']]],
            'capacity_l' => ['label' => ['pl' => 'Pojemność', 'en' => 'Capacity'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['l', 'kg'], 'min' => 0]],
            'spin_speed' => ['label' => ['pl' => 'Prędkość wirowania (obr./min)', 'en' => 'Spin speed (rpm)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 2000]],
            'noise_level' => ['label' => ['pl' => 'Poziom hałasu (dB)', 'en' => 'Noise level (dB)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 120]],
            'sensor_type' => ['label' => ['pl' => 'Typ matrycy (foto)', 'en' => 'Sensor type'], 'type' => AttributeType::Select, 'options' => [['aps_c', ['pl' => 'APS-C', 'en' => 'APS-C']], ['full_frame', ['pl' => 'Pełna klatka', 'en' => 'Full frame']], ['micro43', ['pl' => 'Mikro 4/3', 'en' => 'Micro 4/3']]]],
            'optical_zoom' => ['label' => ['pl' => 'Zoom optyczny (×)', 'en' => 'Optical zoom (×)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 100]],
            'wifi_standard' => ['label' => ['pl' => 'Standard Wi-Fi', 'en' => 'Wi-Fi standard'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['wifi5', ['pl' => 'Wi-Fi 5 (802.11ac)', 'en' => 'Wi-Fi 5 (802.11ac)']], ['wifi6', ['pl' => 'Wi-Fi 6 (802.11ax)', 'en' => 'Wi-Fi 6 (802.11ax)']], ['wifi6e', ['pl' => 'Wi-Fi 6E', 'en' => 'Wi-Fi 6E']], ['wifi7', ['pl' => 'Wi-Fi 7', 'en' => 'Wi-Fi 7']]]],
            'lan_ports' => ['label' => ['pl' => 'Porty LAN', 'en' => 'LAN ports'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 48]],

            // ── Service attributes ──
            'service_type' => ['label' => ['pl' => 'Tryb realizacji', 'en' => 'Delivery mode'], 'type' => AttributeType::Select, 'filterable' => true, 'options' => [['onsite', ['pl' => 'U klienta', 'en' => 'On-site']], ['remote', ['pl' => 'Zdalnie', 'en' => 'Remote']], ['workshop', ['pl' => 'W serwisie', 'en' => 'Workshop']]]],
            'service_duration' => ['label' => ['pl' => 'Czas realizacji', 'en' => 'Duration'], 'type' => AttributeType::Metric, 'rules' => ['units' => ['h', 'min'], 'min' => 0]],
            'sla_days' => ['label' => ['pl' => 'SLA (dni)', 'en' => 'SLA (days)'], 'type' => AttributeType::Number, 'rules' => ['min' => 0, 'max' => 60]],
            'includes_parts' => ['label' => ['pl' => 'Części w cenie', 'en' => 'Parts included'], 'type' => AttributeType::Boolean],
        ];
    }
}
