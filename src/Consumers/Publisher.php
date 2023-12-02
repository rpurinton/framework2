<?php

namespace RPurinton\Framework2\Consumers;

use RPurinton\Framework2\{Log, MySQL, HTTPS, Error};
use RPurinton\Framework2\RabbitMQ\Publisher;

class StatusUpdater
{
    private string $base_url = "https://webapi.mir4global.com/nft/";
    private string $stats_url = "character/";
    private ?Publisher $pub = null;

    public function __construct(private Log $log, private MySQL $sql)
    {
    }

    public function init(): bool
    {
        $this->update_status();
        return true;
    }

    public function update_status(): void
    {
        $this->log->info("Updating status");
        $result = $this->sql->query("SELECT `seq`, `transportID` FROM `sequence` WHERE `tradeType` = '1';") or throw new Error("failed to get current listings");
        while ($row = $result->fetch_assoc()) {
            extract($row);
            $this->log->debug("updating status", [$seq, $transportID]);
            $this->stat_check($seq, $transportID, "summary");
        }
        $this->log->info("Status updated");
    }

    private function stat_check($seq, $transportID, $stat_check): bool
    {
        $payload = [
            'seq' => $seq,
            'transportID' => $transportID,
            'stat_check' => $stat_check,
            'stat_url' => $this->base_url . $this->stats_url . $stat_check . '?' . http_build_query([
                'seq' => $seq,
                'transportID' => $transportID,
                'languageCode' => 'en',
            ])
        ];
        $this->log->debug("publishing stat check", [$payload]);
        if (!$this->pub) $this->pub = new Publisher() or throw new Error("failed to create Publisher");
        $this->pub->publish('stat_checker', $payload) or throw new Error("failed to publish stat check");
        return true;
    }
}
