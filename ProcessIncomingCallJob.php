<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\Client;
use App\Models\Operator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\TelephonyClient;


class ProcessIncomingCallJob implements ShouldQueue
{
   public $tries = 5;

   private $callId;

   public function __construct($callId)
   {
       $this->callId = $callId;
   }

   public function handle()
   {
       $call = Call::find($this->callId);

       if (!$call) {
           return;
       }

       if ($call->status === 'new') {
           $client = Client::where('phone', $call->phone)->first();

           if ($client) {
               $call->client_id = $client->id;
           }

           $operator = Operator::where('available', true)
               ->orderBy('last_call_at')
               ->first();

           if (!$operator) {
               throw new \Exception('No available operators');
           }

           $operator->available = false;
           $operator->save();

           $call->operator_id = $operator->id;
           $call->status = 'assigned';
           $call->save();

           // HTTP-запрос во внешнюю телефонию для назначения звонка оператору.
           // Гарантии внешней системы неизвестны.
           app(TelephonyClient::class)->sendCallAssigned($call->id, $operator->id);

           Log::info('Call assigned', [
               'call_id' => $call->id,
               'operator_id' => $operator->id,
           ]);
       }
   }
}