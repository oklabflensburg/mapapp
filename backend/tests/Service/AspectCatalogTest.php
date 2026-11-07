<?php

namespace App\Tests\Service;

use App\Service\AspectCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class AspectCatalogTest extends TestCase
{
    public function testReadsFileChangesAndRetiredLabels(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aspect-catalog');
        $reader = new AspectCatalog($path, new NullLogger());
        try {
            $catalog = json_decode(file_get_contents(dirname(__DIR__, 2).'/config/examples/aspect-catalog.json'), true);
            file_put_contents($path, json_encode($catalog));
            self::assertSame($catalog, $reader->read());
            $catalog['types'][0]['attributes'][0]['values'] = ['>2'];
            file_put_contents($path, json_encode($catalog));
            self::assertSame(['>2'], $reader->read()['types'][0]['attributes'][0]['values']);
            self::assertSame('< 1 m', $reader->read()['types'][0]['attributes'][0]['labels']['<1']);
            file_put_contents($path, '{"types":[],"preferences":[]}');
            self::assertSame(['types' => [], 'preferences' => []], $reader->read());
        } finally {
            unlink($path);
        }
    }

    public static function invalidCatalogs(): iterable
    {
        yield ['{'];
        yield ['{"types":{},"preferences":[]}'];
        yield ['{"types":[],"types":[],"preferences":[]}'];
        yield ['{"types":[{"name":"bad.type","attributes":[]}],"preferences":[]}'];
        yield ['{"types":[{"name":"footwalk","attributes":{} }],"preferences":[]}'];
        yield ['{"types":[{"name":"footwalk","attributes":[]},{"name":"footwalk","attributes":[]}],"preferences":[]}'];
        yield ['{"types":[{"name":"footwalk","attributes":[{"key":"width","kind":"range","values":["<1","<1"]}]}],"preferences":[]}'];
        yield ['{"types":[],"preferences":[{"key":"aspect:x","label":"Bad","type":"missing","aspect":"width","values":[">2"]}]}'];
        yield ['{"types":[{"name":"footwalk","attributes":[{"key":"width","kind":"range","values":[">2"]}]}],"preferences":[{"key":"aspect:x","label":"Bad","type":"footwalk","aspect":"width","values":[">2"]}]}'];
        yield ['{"types":[{"name":"footwalk","attributes":[{"key":"width","kind":"range","values":[">2"]}]}],"preferences":[{"key":"aspect:x","label":"Bad","type":"footwalk","aspect":"width","values":[">2"],"match":"sometimes"}]}'];
    }

    #[DataProvider('invalidCatalogs')]
    public function testInvalidCatalogIsUnavailable(string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aspect-catalog');
        file_put_contents($path, $contents);
        try {
            $this->expectException(ServiceUnavailableHttpException::class);
            (new AspectCatalog($path, new NullLogger()))->read();
        } finally {
            unlink($path);
        }
    }

    public function testMissingFileIsUnavailable(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);
        (new AspectCatalog('/missing/aspect-catalog.json', new NullLogger()))->read();
    }
}
