<?php

include(__DIR__ . '/config.php');
 
use PhpAmqpLib\Message\AMQPMessage; 
use PhpAmqpLib\Connection\AMQPConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class WorkerSender
{    
 
    
    /**
     * Sends an invoice generation task to the workers
     * 
     * @param Object $data
     */ 
    public  function execute($data, $exchange, $queue)
    { 
        
        $connection = new AMQPConnection(HOST, PORT, USER, PASS,VHOST);

        $channel = $connection->channel();

        $channel->queue_declare(
            $queue,             #queue - Queue names may be up to 255 bytes of UTF-8 characters
            false,              #passive - can use this to check whether an exchange exists without modifying the server state
            false,              #durable, make sure that RabbitMQ will never lose our queue if a crash occurs - the queue will survive a broker restart
            false,              #exclusive - used by only one connection and the queue will be deleted when that connection closes
            false               #auto delete - queue is deleted when last consumer unsubscribes
            );
        /*
            name: $exchange
            type: direct
            passive: false
            durable: true // the exchange will survive server restarts
            auto_delete: false //the exchange won't be deleted once the channel is closed.
        */     
        $channel->exchange_declare($exchange, 'direct', false, true, false);    

        $payload = json_encode($data);  
 
        $msg = new AMQPMessage(
            $payload,
            array('content_type' => 'application/json', 'delivery_mode' => 2)); # make message persistent, so it is not lost if server crashes or quits
            
            
        $channel->basic_publish(
            $msg,               #message 
            '',                 #exchange
            $queue              #routing key (queue)
            );
            
        $channel->close();
        $connection->close();
     }
}