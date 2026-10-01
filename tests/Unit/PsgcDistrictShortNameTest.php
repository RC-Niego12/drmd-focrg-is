<?php

namespace Tests\Unit;

use App\Services\PsgcDistrictService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class PsgcDistrictShortNameTest extends TestCase
{
    public static function shortNameProvider(): array
    {
        return [
            ['Agusan del Sur', '1st_District', 'ADS1'],
            ['Agusan del Sur', '2nd District', 'ADS2'],
            ['Agusan del Sur', 'District 1', 'ADS1'],
            ['Surigao del Norte', '1st_District', 'SDN1'],
            ['Surigao del Norte', '2nd_District', 'SDN2'],
            ['Surigao del Sur', '3rd District', 'SDS3'],
            ['Surigao del Sur', 'SDS1', 'SDS1'],
            ['Agusan del Norte', 'Lone_District', 'Lone ADN'],
            ['Agusan del Norte', '1st_District', 'Lone ADN'],
            ['Agusan del Norte', 'Lone Butuan City', 'Lone Butuan City'],
            ['Dinagat Islands', 'Lone_District', 'Lone PDI'],
            ['Province of Dinagat Islands', 'District 1', 'Lone PDI'],
        ];
    }

    #[DataProvider('shortNameProvider')]
    public function test_to_short_name_maps_sheet_labels(string $province, string $district, string $expected): void
    {
        $service = app(PsgcDistrictService::class);

        $this->assertSame($expected, $service->toShortName($province, $district));
    }

    public static function localityAliasProvider(): array
    {
        return [
            ['Sta. Josefa', 'Santa Josefa'],
            ['Santa Josefa', 'Sta. Josefa'],
            ['City of Bayugan', 'Bayugan City'],
            ['Bayugan City', 'City of Bayugan'],
            ['Sto. Niño', 'Santo Niño'],
        ];
    }

    #[DataProvider('localityAliasProvider')]
    public function test_locality_aliases_match_common_abbreviations(string $left, string $right): void
    {
        $service = app(PsgcDistrictService::class);
        $method = new ReflectionMethod(PsgcDistrictService::class, 'sameLocalityName');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $left, $right));
    }
}
