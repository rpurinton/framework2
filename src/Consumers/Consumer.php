<?php

namespace RPurinton\Framework2\Consumers;

use Bunny\{Channel, Message};
use React\EventLoop\LoopInterface;
use RPurinton\Framework2\{Log, MySQL, HTTPS, Error};
use RPurinton\Framework2\RabbitMQ\Consumer;

class StatCheckConsumer
{
    public function __construct(private Log $log, private MySQL $sql, private LoopInterface $loop, private Consumer $mq)
    {
    }

    public function init(): bool
    {
        $this->sql->connect() or throw new Error("failed to connect to MySQL");
        $this->mq->connect($this->loop, "stat_checker", $this->stats_callback(...)) or throw new Error("failed to connect to stat_check queue");
        return true;
    }

    public function stats_callback(Message $message, Channel $channel): bool
    {
        $this->log->debug("received stats callback", [$message->content]);
        $data = json_decode($message->content, true);
        $this->validate_stats_callback($data) or throw new Error("received invalid stats callback");
        $this->insert_stats($data) or throw new Error("failed to insert stats");
        $channel->ack($message);
        return true;
    }

    private function validate_stats_callback($data): bool
    {
        return is_array($data) && isset($data['seq']) && isset($data['transportID']) && isset($data['stat_check']) && isset($data['stat_url']);
    }

    private function insert_stats($data): bool
    {
        extract($data) or throw new Error("failed to extract data");
        $response = HTTPS::get($stat_url) or throw new Error("failed to get url");
        $response_escaped = $this->sql->escape($response);
        if ($stat_check !== "summary") {
            $query = "INSERT INTO `$stat_check` (
                `transportID`, `json`
            ) VALUES (
                '$transportID', '$response_escaped'
            ) ON DUPLICATE KEY UPDATE `json` = '$response_escaped';";
        } else {
            $query = "INSERT INTO `summary` (
                `seq`, `json`
            ) VALUES (
                '$seq', '$response_escaped'
            ) ON DUPLICATE KEY UPDATE `json` = '$response_escaped';";
            $tradeType = json_decode($response, true)['data']['tradeType'] ?? null;
            if ($tradeType) {
                $tradeType = $this->sql->escape($tradeType);
                $query .= "UPDATE `sequence` SET `tradeType` = '$tradeType' WHERE `seq` = '$seq';";
            }
        }
        $this->sql->multi($query);
        $this->log->debug("inserted stats", [$query]);
        return true;
    }
}
