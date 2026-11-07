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
            file_put_contents($path, json_encode(['wayVocabularies' => $data]));
            self::assertSame($data, $reader->read());
            file_put_contents($path, '{"wayVocabularies":{}}');
            self::assertSame([], $reader->read());
        } finally {
            unlink($path);
        }
    }

    public static function invalidFiles(): iterable
    {
        yield ['{'];
        yield ['{"wayVocabularies":[]}'];
        yield ['{"wayVocabularies":{"footway":[],"footway":[]}}'];
        yield ['{"wayVocabularies":{"footway":[{"key":"x","values":[]},{"key":"x","values":[]}]}}'];
        yield ['{"wayVocabularies":{"footway":[{"key":"x","values":["yes","yes"]}]}}'];
        yield ['{"wayVocabularies":{"footway":[{"key":"","values":[]}]}}'];
        yield ['{"wayVocabularies":{"footway":[{"key":"x","values":[1]}]}}'];
        yield ['{"wayVocabularies":{"footway":{}}}'];
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
