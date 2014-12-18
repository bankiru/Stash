<?php
namespace Stash;

use Stash\Exception\Exception;

class ItemWithTags extends Item
{
    const VERSION = "1.0";

    /**
     * {@inheritdoc}
     */
    public function set($data, $ttl = null, array $tags = array())
    {
        try {
            return $this->executeSet($data, $ttl, $tags);
        } catch (Exception $e) {
            $this->logException('Setting value in cache caused exception.', $e);
            $this->disable();

            return false;
        }
    }

    /**
     * @param array $tags
     * @return bool
     */
    public function clearByTags(array $tags = array())
    {
        if (!$tags) {
            return false;
        }

        /** @var Item $tagItem */
        foreach ($this->pool->getItemIterator($this->mangleTags($tags)) as $tagItem) {
            $tagItem->clear();
        }

        return true;
    }

    protected function executeSet($data, $time, array $tags = array())
    {
        if ($this->isDisabled()) {
            return false;
        }

        if (!isset($this->key)) {
            return false;
        }

        $store = array();
        $store['return'] = $data;
        $store['createdOn'] = time();
        $store['tags'] = array();
        if (count($tags)) {
            $mangledTags = $this->mangleTags($tags);
            $mangledTagsToTags = array_combine($mangledTags, $tags);

            /** @var Item $tagItem */
            foreach ($this->pool->getItemIterator($mangledTags) as $tagItem) {
                $tagVersion = $tagItem->get();
                if ($tagItem->isMiss()) {
                    $tagVersion = $this->generateNewTagVersion();
                    $tagItem->set($tagVersion);
                }
                $store['tags'][$mangledTagsToTags[$tagItem->getKey()]] = $tagVersion;
            }
        }

        if (isset($time)) {
            if ($time instanceof \DateTime) {
                $expiration = $time->getTimestamp();
                $cacheTime = $expiration - $store['createdOn'];
            } else {
                $cacheTime = isset($time) && is_numeric($time) ? $time : self::$cacheTime;
            }
        } else {
            $cacheTime = self::$cacheTime;
        }

        $expiration = $store['createdOn'] + $cacheTime;

        if ($cacheTime > 0) {
            $expirationDiff = rand(0, floor($cacheTime * .15));
            $expiration -= $expirationDiff;
        }

        if ($this->stampedeRunning == true) {
            $spkey = $this->key;
            $spkey[0] = 'sp'; // change "cache" data namespace to stampede namespace
            $this->driver->clear($spkey);
            $this->stampedeRunning = false;
        }

        return $this->driver->storeData($this->key, $store, $expiration);
    }

    protected function validateRecord($validation, &$record)
    {
        $expiration = ($_ = &$record['expiration']);

        if (!empty($record['data']['tags'])) {
            $mangledTags = $this->mangleTags(array_keys($record['data']['tags']));
            $mangledTagsWithVersion = array_combine($mangledTags, array_values($record['data']['tags']));

            foreach ($this->pool->getItemIterator($mangledTags) as $tagItem) {
                $tagVersion = $tagItem->get();

                if ($tagVersion != $mangledTagsWithVersion[$tagItem->getKey()]) {
                    unset($record['expiration']);
                    break;
                }
            }
        }

        parent::validateRecord($validation, $record);

        if ($expiration !== null) {
            $record['expiration'] = $expiration;
        }
    }

    /**
     * Mangles the name to deny intersection of tag keys & data keys.
     * Mangled tag names are NOT saved in memcache $combined[0] value,
     * mangling is always performed on-demand (to same some space).
     *
     * @param string $tag    Tag name to mangle.
     * @return string        Mangled tag name.
     */
    private function mangleTag($tag)
    {
        return __CLASS__ . "_" . self::VERSION . "_" . $tag;
    }


    /**
     * The same as mangleTag(), but mangles a list of tags.
     *
     * @see self::mangleTag
     * @param array $tags   Tags to mangle.
     * @return array        List of mangled tags.
     */
    private function mangleTags(array $tags)
    {
        return array_map(array($this, 'mangleTag'), $tags);
    }

    /**
     * Generates a new unique identifier for tag version.
     *
     * @return string Globally (hopefully) unique identifier.
     */
    private function generateNewTagVersion()
    {
        static $counter = 0;
        return sha1(microtime() . getmypid() . uniqid('') . ++$counter);
    }
}