<?php namespace KafkaPhp;

use Exception;
use JetBrains\PhpStorm\Deprecated;
use JetBrains\PhpStorm\NoReturn;
use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\ConsumerTopic;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\Producer;
use RdKafka\TopicConf;
use RdKafka\TopicPartition;

class KafkaPhp
{
    private const msgSize = 205000000;
    private array $opts = ['topic' => 'default', 'partition' => 0,];
    private array $producerConfList = ['bootstrap.servers' => null, 'queue.buffering.max.ms' => '1', 'message.max.bytes' => self::msgSize,];
    private array $consumerConfList = ['bootstrap.servers' => null, 'receive.message.max.bytes' => self::msgSize, 'allow.auto.create.topics' => 'true', 'group.id' => 'default', 'auto.offset.reset' => 'earliest', 'enable.auto.commit' => 'false', 'enable.auto.offset.store' => 'false', 'enable.partition.eof' => 'true', 'fetch.wait.max.ms' => '500',];
    private array $topicConfList = [];
    private array $brokerConfList = [];
    private KafkaConsumer $consumer;
    private Producer $producer;

    function __construct(string $bootstrap_servers)
    {
        $this->producerConfList['bootstrap.servers'] = $bootstrap_servers;
        $this->consumerConfList['bootstrap.servers'] = $bootstrap_servers;
    }

    public function __get(string $key): mixed
    {
        if ($key === 'topic') return $this->opts['topic'];
        if ($key === 'partition') return $this->opts['partition'];
        if ($key === 'group_instance_id') return $this->consumerConfList['group.instance.id'];
        throw new Exception("undefined key");
    }

    public function __set(string $key, string $value): void
    {
        if ($key === 'topic') {
            $this->opts['topic'] = $value;
            return;
        }
        if ($key === 'partition') {
            $this->opts['partition'] = $value;
            return;
        }
        if ($key === 'group_instance_id') {
            $this->consumerConfList['group.instance.id'] = $value;
            return;
        }
        throw new Exception("undefined key");
    }

    public function consumerConf(): Conf
    {
        $conf = new Conf();
        $conf->setRebalanceCb(function () {
        });
        $conf->setOffsetCommitCb(function () {
        });
        foreach ($this->consumerConfList as $key => $value) {
            $conf->set($key, $value);
        }
        return $conf;
    }

    public function producerConf(): Conf
    {
        $conf = new Conf();
        foreach ($this->producerConfList as $key => $value) {
            $conf->set($key, $value);
        }
        return $conf;
    }

    public function topicConf(): TopicConf
    {
        $conf = new TopicConf();
        foreach ($this->topicConfList as $key => $value) {
            $conf->set($key, $value);
        }
        return $conf;
    }

    public function topicExists($topicName): bool
    {
        $consumer = new KafkaConsumer($this->consumerConf());
        $topic = $consumer->newTopic($topicName);
        $metadata = $consumer->getMetadata(true, $topic, 5000);
        $exists = $metadata->getTopics()->count() !== 0;
        $consumer->unsubscribe();
        $consumer->close();
        return $exists;
    }

    public function createTopic($topicName): void
    {
        $consumer = new KafkaConsumer($this->consumerConf());
        $topic = $consumer->newTopic($topicName);
        $metadata = $consumer->getMetadata(true, $topic, 5000);
        if ($metadata->getTopics()->count() === 0) {
            $topic->newPartitions(1)->create();
        }
        $consumer->unsubscribe();
        $consumer->close();
    }

    public function produceFile(string $filepath): void
    {
        if (!is_file($filepath)) {
            throw new Exception("invalid file $filepath", 6);
        }
        $payload = file_get_contents($filepath);
        $producer = new Producer($this->producerConf());
        $topic = $producer->newTopic($this->opts['topic'], $this->topicConf());
        $topic->producev($this->opts['partition'], 0, $payload, null, ['filename' => basename($filepath), 'filesize' => filesize($filepath)]);
        $producer->flush(1000 * 30);
    }

    public function produce(mixed $payload, array $headers = []): void
    {
        $producer = new Producer($this->producerConf());
        $topic = $producer->newTopic($this->opts['topic'], $this->topicConf());
        $topic->producev(partition: $this->opts['partition'], msgflags: 0, payload: $payload, headers: $headers);
        $producer->flush(1000 * 30);
    }

    public function commit(Message $message): void
    {
        $consumer = new KafkaConsumer($this->consumerConf());
        $consumer->subscribe([$this->opts['topic']]);
        $consumer->commit($message);
    }

    #[Deprecated] public function offsetStore(int $offset): void
    {
        $consumer = new Consumer($this->consumerConf());
        $topic = $consumer->newTopic($this->opts['topic'], $this->topicConf());
        $topic->offsetStore($this->opts['partition'], $offset);
    }

    public function getCommittedOffset(): int
    {
        $consumer = new KafkaConsumer($this->consumerConf());
        $offsets = $consumer->getCommittedOffsets([new TopicPartition($this->opts['topic'], $this->opts['partition'])], 10000);
        return $offsets[0]->getOffset();
    }

    public function watermarkOffsets(): array
    {
        $consumer = new KafkaConsumer($this->consumerConf());
        $low = null;
        $high = null;
        $consumer->queryWaterMarkOffsets($this->opts['topic'], $this->opts['partition'], $low, $high, 1000);
        return [$low, $high];
    }

    #[Deprecated] public function getOffsetPosition(): int
    {
        $consumer = new KafkaConsumer($this->consumerConf());
        $topicPartitions = $consumer->getOffsetPositions([new TopicPartition($this->opts['topic'], $this->opts['partition'])]);
        return $topicPartitions[0]->getOffset();
    }

    #[Deprecated] public function seek(): void
    {
    }

    #[Deprecated] public function _partitionOffsetStore(int $offset): void
    {
        new TopicPartition($this->opts['topic'], $this->opts['partition'], $offset);
    }

    public function getMessages(int $count = 0, int $from_offset = RD_KAFKA_OFFSET_STORED): array
    {
        $messages = [];
        $lowConsumer = new Consumer($this->consumerConf());
        $lowTopic = $lowConsumer->newTopic($this->opts['topic'], $this->topicConf());
        $queue = $lowConsumer->newQueue();
        $lowTopic->consumeQueueStart($this->opts['partition'], $from_offset, $queue);
        $eof = null;
        $to_offset = null;
        for ($i = 0; !$count || $i < $count; $i++) {
            $message = $queue->consume(1000);
            if (null === $message || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                $eof = true;
                unset($message);
                break;
            } else {
                $to_offset = $message->offset;
                $messages[] = $message;
            }
        }
        return [$messages, $eof, $from_offset, $to_offset];
    }

    public function getMessage(int $from_offset = RD_KAFKA_OFFSET_STORED): array
    {
        $messages = [];
        $lowConsumer = new Consumer($this->consumerConf());
        $lowTopic = $lowConsumer->newTopic($this->opts['topic'], $this->topicConf());
        $lowTopic->consumeStart($this->opts['partition'], $from_offset);
        $eof = null;
        $to_offset = null;
        $message = $lowTopic->consume($this->opts['partition'], 1000);
        if (null === $message || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            $eof = true;
            unset($message);
        } else {
            $to_offset = $message->offset;
            $messages[] = $message;
        }
        $lowTopic->consumeStop($this->opts['partition']);
        return [$messages, $eof, $from_offset, $to_offset];
    }

    public function getVeryFirstMessage(): Message
    {
        [$messages, $eof, $from_offset, $to_offset] = $this->getMessage(RD_KAFKA_OFFSET_BEGINNING);
        return $messages[0];
    }

    #[Deprecated] public function deleteMessage(Message $message)
    {
        throw new Exception("Kafka committed messages cannot be directly deleted. Once messages are committed, they are handled by the log retention policy.
        Set this for topic with retention.ms or retention.bytes");
    }

    #[NoReturn] public function kafkaConsumerShutdownHandler(KafkaConsumer $consumer, bool $commit): void
    {
        echo "***Shutting down(unsubscribe,close)...\n";
        if ($commit) {
            echo "committing...\n";
            $consumer->commit();
        } else {
            echo "no commit\n";
        }
        $consumer->unsubscribe();
        $consumer->close();
        exit(0);
    }

    public function kafkaRebalanceCallback(KafkaConsumer $consumer, int $err, array $partitions = null): void
    {
        echo "rebalance...\n";
        switch ($err) {
            case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                echo "rebalance: assign...\n";
                $consumer->assign($partitions);
                break;
            case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                echo "rebalance: revoke...\n";
                $consumer->assign(null);
                break;
            default:
                throw new Exception("rebalance: unknown error-code: " . $err);
        }
    }
}