<?php

declare(strict_types=1);

namespace CodeVault\Tests\Unit;

use CodeVault\Billing\VatNumberValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatNumberValidatorTest extends TestCase
{
    private VatNumberValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new VatNumberValidator();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function validNumbers(): array
    {
        return [
            'Germany' => ['DE', '123456789'],
            'Germany with prefix in number' => ['DE', 'DE123456789'],
            'France (alphanumeric prefix)' => ['FR', 'AB123456789'],
            'Ireland (7 digits + letter)' => ['IE', '1234567X'],
            'Ireland (digit-letter-5digit-letter)' => ['IE', '1X23456X'],
            'Netherlands (B + 2 digits)' => ['NL', '123456789B01'],
            'UK 9-digit' => ['GB', '123456789'],
            'Belgium with leading zero' => ['BE', '0123456789'],
            'Belgium without leading zero' => ['BE', '123456789'],
            'lowercase input is tolerated' => ['de', '123456789'],
            'spaces and dashes are stripped' => ['DE', '123 456-789'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_recognizes_valid_format(string $country, string $number): void
    {
        $this->assertTrue($this->validator->isValidFormat($country, $number));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidNumbers(): array
    {
        return [
            'too short for Germany' => ['DE', '12345'],
            'letters where Germany expects digits' => ['DE', 'ABCDEFGHI'],
            'empty number' => ['DE', ''],
            'unsupported country' => ['US', '123456789'],
            'unsupported country ZZ' => ['ZZ', '123456789'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_invalid_format(string $country, string $number): void
    {
        $this->assertFalse($this->validator->isValidFormat($country, $number));
    }

    public function test_supported_countries_includes_the_full_eu_vat_area_and_uk(): void
    {
        $countries = $this->validator->supportedCountries();

        $this->assertContains('DE', $countries);
        $this->assertContains('FR', $countries);
        $this->assertContains('GB', $countries);
        $this->assertContains('EL', $countries);
    }
}
