<?php

namespace App\Tests\Service;

use App\Service\EditingVocabulary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class EditingVocabularyTest extends TestCase
{
    public function testPreservesChoicesAndReadsChangesEveryTime(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vocabulary');
        $reader = new EditingVocabulary($path, new NullLogger());
        try {
            $data = ['footway' => [['key' => 'note', 'values' => []], ['key' => 'surface', 'values' => ['gravel', 'asphalt']]], 'motorway' => []];
            file_put_contents($path, json_encode(['way' => $data]));
            self::assertSame($data, $reader->read());
            file_put_contents($path, '{"way":{}}');
            self::assertSame([], $reader->read());
        } finally {
            unlink($path);
        }
    }

    public static function invalidFiles(): iterable
    {
        yield ['{'];
        yield ['{"way":[]}'];
        yield ['{"way":{"footway":[],"footway":[]}}'];
        yield ['{"way":{"footway":[{"key":"x","values":[]},{"key":"x","values":[]}]}}'];
        yield ['{"way":{"footway":[{"key":"x","values":["yes","yes"]}]}}'];
        yield ['{"way":{"footway":[{"key":"","values":[]}]}}'];
        yield ['{"way":{"footway":[{"key":"x","values":[1]}]}}'];
        yield ['{"way":{"footway":{}}}'];
    }
    #[DataProvider('invalidFiles')]
    public function testRejectsInvalidConfiguration(string $contents): void
    {
        $path = tempnam(sys_get_temp_dir(), 'vocabulary');
        try {
            file_put_contents($path, $contents);
            $this->expectException(ServiceUnavailableHttpException::class);
            (new EditingVocabulary($path, new NullLogger()))->read();
        } finally {
            unlink($path);
        }
    }

    public function testMissingFileIsUnavailable(): void
    {
        $this->expectException(ServiceUnavailableHttpException::class);
        (new EditingVocabulary('/missing/vocabulary.json', new NullLogger()))->read();
    }
}
