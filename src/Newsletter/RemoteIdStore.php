<?php

declare(strict_types=1);

/**
 * OpenDXP Campaigns.
 *
 * LICENSE
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  2026 instride AG (https://instride.ch)
 * @license    https://github.com/instride-ch/opendxp-campaigns/blob/main/gpl-3.0.txt GNU General Public License version 3 (GPLv3)
 */

namespace Instride\Bundle\OpenDxpCampaignsBundle\Newsletter;

use OpenDxp\Model\Element\ElementInterface;
use OpenDxp\Model\Element\Note;
use OpenDxp\Model\Element\Note\Listing as NoteListing;
use OpenDxp\Model\Element\Service as ElementService;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Stores and reads provider-assigned remote IDs (Mailchimp interest / interest
 * category IDs) for exported elements, keyed by (element, connector, list).
 *
 * The mapping lives in a single OpenDXP Note per element (type {@see self::NOTE_TYPE}),
 * whose data holds one entry per "connector:list" key. Notes survive class-definition
 * re-imports, so the local<->remote mapping is never lost.
 *
 * Reads are cached (PSR-6 pool via CacheInterface) plus memoized in-request, so
 * exporting many members or resyncing segments does not hammer the database. The
 * cache is invalidated on every write.
 *
 * Not final so it can be mocked in unit tests.
 */
class RemoteIdStore
{
    private const string NOTE_TYPE = 'opendxp_campaigns.export';
    private const string NOTE_TITLE = 'OpenDXP Campaigns: exported remote IDs';

    /**
     * In-request memoization: elementCacheKey => (mapKey => remoteId).
     *
     * @var array<string, array<string, string>>
     */
    private array $memo = [];

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getRemoteId(ElementInterface $object, string $connector, string $list): ?string
    {
        return $this->loadMap($object)[$this->mapKey($connector, $list)] ?? null;
    }

    public function setRemoteId(ElementInterface $object, string $connector, string $list, string $remoteId): void
    {
        $note = $this->findNote($object) ?? $this->createNote($object);
        $note->addData($this->mapKey($connector, $list), 'text', $remoteId);
        $note->setDate(\time());
        $note->save();

        $this->invalidate($object);
    }

    public function removeRemoteId(ElementInterface $object, string $connector, string $list): void
    {
        $note = $this->findNote($object);

        if ($note === null) {
            return;
        }

        $data = $note->getData();
        unset($data[$this->mapKey($connector, $list)]);

        if ($data === []) {
            $note->delete();
        } else {
            $note->setData($data);
            $note->save();
        }

        $this->invalidate($object);
    }

    /**
     * All stored remote IDs for the given element and connector.
     *
     * @return array<string, string> list identifier => remote ID
     */
    public function allRemoteIds(ElementInterface $object, string $connector): array
    {
        $prefix = $connector . ':';
        $result = [];

        foreach ($this->loadMap($object) as $key => $remoteId) {
            if (\str_starts_with($key, $prefix)) {
                $result[\substr($key, \strlen($prefix))] = $remoteId;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function mapKey(string $connector, string $list): string
    {
        return $connector . ':' . $list;
    }

    /**
     * @return array<string, string>
     */
    private function loadMap(ElementInterface $object): array
    {
        $cacheKey = $this->cacheKey($object);

        return $this->memo[$cacheKey] ??= $this->cache->get(
            $cacheKey,
            function (ItemInterface $item) use ($object): array {
                return $this->readNoteData($object);
            },
        );
    }

    /**
     * @return array<string, string>
     */
    private function readNoteData(ElementInterface $object): array
    {
        $note = $this->findNote($object);

        if ($note === null) {
            return [];
        }

        $map = [];
        foreach ($note->getData() as $key => $entry) {
            $value = \is_array($entry) ? ($entry['data'] ?? null) : $entry;
            if (\is_scalar($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }

    private function findNote(ElementInterface $object): ?Note
    {
        $id = $object->getId();

        if ($id === null) {
            return null;
        }

        $listing = new NoteListing();
        $listing->setCondition('cid = :cid AND ctype = :ctype AND type = :type', [
            'cid' => $id,
            'ctype' => ElementService::getElementType($object),
            'type' => self::NOTE_TYPE,
        ]);
        $listing->setOrderKey('date');
        $listing->setOrder('DESC');
        $listing->setLimit(1);

        return $listing->getNotes()[0] ?? null;
    }

    private function createNote(ElementInterface $object): Note
    {
        $note = new Note();
        $note->setElement($object);
        $note->setType(self::NOTE_TYPE);
        $note->setTitle(self::NOTE_TITLE);
        $note->setDate(\time());

        return $note;
    }

    private function invalidate(ElementInterface $object): void
    {
        $cacheKey = $this->cacheKey($object);
        unset($this->memo[$cacheKey]);
        $this->cache->delete($cacheKey);
    }

    private function cacheKey(ElementInterface $object): string
    {
        return \sprintf(
            'opendxp_campaigns_remoteids_%s_%s',
            ElementService::getElementType($object) ?? 'unknown',
            (string) $object->getId(),
        );
    }
}