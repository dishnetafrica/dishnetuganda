<?php
declare(strict_types=1);
/**
 * TEST ONLY (5.18.104, root docs/73 §H.3.6 change 7, §H.3.24 V31, V34): the Evolution client with the registry's answer
 * for one channel planted — a salesperson's own number — loaded after the tree's EvolutionApiService and ChannelContext.
 */
if (!class_exists('VmOwnerEvo', false)) {
    /** The Evolution client with the registry's answer for one channel planted: a salesperson's own number. */
    final class VmOwnerEvo extends EvolutionApiService
    {
        public $ctxRow = [];
        public function registryOn(): bool { return true; }
        public function channelContext(string $channel): ?ChannelContext
        {
            return $channel === (string)($this->ctxRow['channel_id'] ?? '') ? new ChannelContext($this->ctxRow, 'Sales-UG') : null;
        }
    }
}

