<?php

declare(strict_types=1);

namespace OCA\DcnFinder\Service;

use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;
use Psr\Log\LoggerInterface;

/**
 * Searches inside files, by asking Nextcloud's full-text index.
 *
 * The interfaces live in core (`OCP\FullTextSearch`) and the manager is always
 * injectable — `IFullTextSearchManager::isAvailable()` simply answers false
 * until the `fulltextsearch` app registers its services. So this is a soft
 * dependency: no entry in info.xml, and the app works exactly as before on an
 * instance that has no index.
 *
 * What comes back is a *window*, not a set. The index pages its own results and
 * ranks them, so this returns the best {@see WINDOW} matches in that order, and
 * the caller intersects that window with the structured query. Everything
 * else in this app is exhaustive; this is the one place that is not, and the
 * result page has to say so rather than present a ranked sample as a count.
 */
class ContentSearchService
{
    /**
     * How many ranked documents to pull before the structured filters are
     * applied to them. Generous, because the filters run *after*: a window of
     * one page would let a mimetype filter empty out a result set that the index
     * had plenty of matches for. Bounded, because the whole point of a window is
     * that the index is not asked to enumerate.
     */
    public const WINDOW = 500;

    /** The provider `files_fulltextsearch` registers; its document ids are file ids. */
    private const FILES_PROVIDER = 'files';

    public function __construct(
        private IFullTextSearchManager $fullTextSearch,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Whether searching inside files is possible at all on this instance.
     *
     * Read at bootstrap and handed to the frontend, so the "search in contents"
     * choice is drawn — or not — in the same paint as the rest of the search
     * bar rather than appearing a round trip later.
     */
    public function isAvailable(): bool
    {
        try {
            return $this->fullTextSearch->isAvailable();
        } catch (\Throwable $e) {
            $this->logger->warning('full-text search availability check failed', ['exception' => $e]);

            return false;
        }
    }

    /**
     * The best matches for a phrase, most relevant first.
     *
     * Rank is the *position* the index returned a document in, not its score.
     * Elasticsearch scores its hits perfectly well — 3.68 against 0.56 for a
     * two-hit query here — but `fulltextsearch_elasticsearch` reads `_score`
     * with a string getter and hands every document `getScore() === '0'`. The
     * order it returns them in is still the ranking, so that is what this uses:
     * one less thing to be wrong, and it holds for any platform, since ordering
     * results is the one thing every search backend does.
     *
     * @return array{ids: list<int>, ranks: array<int,int>, excerpts: array<int,string>}
     *         `ids` best first; `ranks` and `excerpts` keyed by file id, rank 0
     *         being the best match. Empty when the index is unavailable, errors,
     *         or knows nothing — all three mean the same to a caller: no content
     *         matches.
     */
    public function search(string $uid, string $phrase): array
    {
        $empty = ['ids' => [], 'ranks' => [], 'excerpts' => []];

        if ($phrase === '' || !$this->isAvailable()) {
            return $empty;
        }

        try {
            $results = $this->fullTextSearch->search([
                'providers' => self::FILES_PROVIDER,
                'search'    => $phrase,
                'size'      => self::WINDOW,
                'page'      => 1,
            ], $uid);
        } catch (\Throwable $e) {
            // A search that cannot reach its index is a failed content search,
            // not a failed search: the caller falls back to the structured part
            // rather than showing the user an error they cannot act on.
            $this->logger->warning('full-text search failed', ['exception' => $e]);

            return $empty;
        }

        $ids = [];
        $ranks = [];
        $excerpts = [];

        foreach ($results as $result) {
            /** @var ISearchResult $result */
            foreach ($result->getDocuments() as $document) {
                /** @var IIndexDocument $document */
                $fileId = (int)$document->getId();
                if ($fileId <= 0 || isset($ranks[$fileId])) {
                    continue;
                }

                $ranks[$fileId] = count($ids);
                $ids[] = $fileId;
                $excerpt = $this->firstExcerpt($document);
                if ($excerpt !== '') {
                    $excerpts[$fileId] = $excerpt;
                }
            }
        }

        return ['ids' => $ids, 'ranks' => $ranks, 'excerpts' => $excerpts];
    }

    /**
     * One fragment of the matched text, to show under the file name.
     *
     * The shape of an excerpt is the platform's business — Elasticsearch
     * highlights, the SQL platform slices around the term — so this reads
     * defensively rather than assuming a key.
     */
    private function firstExcerpt(IIndexDocument $document): string
    {
        foreach ($document->getExcerpts() as $excerpt) {
            $text = is_array($excerpt) ? (string)($excerpt['excerpt'] ?? '') : (string)$excerpt;
            $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
