<?php
declare(strict_types=1);

namespace App\Integrations\UniversityApi;

/** เลือก driver ตามค่า UNI_API_DRIVER */
final class UniversityApiFactory
{
    public static function make(): UniversityApiInterface
    {
        $cfg = config('university_api');
        return match ($cfg['driver'] ?? 'none') {
            'mock'   => new MockUniversityApi(),
            'rmutsv' => new RmutsvApiClient($cfg),
            default  => new NullUniversityApi(),
        };
    }

    public static function enabled(): bool
    {
        return (config('university_api.driver') ?? 'none') !== 'none';
    }
}
