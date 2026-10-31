<?php

namespace App\Dto;

use App\Service\TagKeyLabeler;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class TagTranslationFilter
{
    public function __construct(
        #[Assert\Choice(choices: TagKeyLabeler::SUPPORTED_LOCALES)]
        public string $locale = 'de',
        #[Assert\Length(max: 200)]
        public string $q = '',
        public bool $untranslated = false,
        #[Assert\Range(min: 1, max: 100000)]
        public int $page = 1,
        #[Assert\Choice(choices: ['', 'osm', 'custom'])]
        public string $category = '',
        #[Assert\Choice(choices: ['all', 'ignored', 'not_ignored'])]
        public string $ignore = 'not_ignored',
    ) {
    }
}
