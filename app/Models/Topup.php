<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OTIFSolutions\Laravel\Settings\Models\Setting;

class Topup extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    protected $casts = [
        'response' => 'array',
        'pin' => 'array'
    ];
    protected $appends = ['message'];

    public function operator(){
        return $this->belongsTo('App\Models\Operator');
    }

    public function user(){
        return $this->belongsTo('App\Models\User');
    }

    public function invoice(){
        return $this->belongsTo('App\Models\Invoice');
    }

    public function file_entry(){
        return $this->belongsTo('App\Models\FileEntry');
    }

    public function getMessageAttribute(){
        switch ($this['status']){
            case "PENDING":
                return "Transaction is paid. But its pending topup. Please wait a few minuites for the status to update.";
            case "SUCCESS":
                return "Transaction completed successfully.";
            case "FAIL":
                return isset($this['response']['message'])?$this['response']['message']: "Transaction Failed. No response";
            case "PENDING_PAYMENT":
                return "Transaction is pending payment";
            case "REFUNDED":
                return "Topup has been refunded. It failed due to Error : ".(isset($this['response']['message'])?$this['response']['message']: "Unknown");
            default:
                return "Error : Unknown Status found.";
        }
    }

    public function sendTopup($sendResponse=false)
    {
        if (isset($this['operator']) && isset($this['operator']['country'])) {
            $system = User::admin();
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $system['reloadly_api_url'] . "/topups");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "Content-Type:application/json",
                "Authorization: Bearer " . Setting::get('reloadly_api_token')
            ));

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'recipientPhone' => [
                    'countryCode' => $this['operator']['country']['iso'],
                    'number' => $this['number']
                ],
                'operatorId' => $this['operator']['rid'],
                'amount' => $this['is_local'] ? $this['topup'] : $this['topup'] / $this['operator']['fx_rate'],
                'useLocalAmount' => $this['is_local'] ? "true" : "false"
            ]));

            $response = curl_exec($ch);
            curl_close($ch);
            \App\Models\Log::create([
                'task' => 'SEND_TOPUP',
                'params' => 'TOPUP_ID:' . $this['id'] . ' PHONE:' . $this['number'] . ' TOPUP:' . $this['topup'],
                'response' => $response
            ]);
            $this['response'] = json_decode($response,true);
            if (isset($this['response']['transactionId']) && $this['response']['transactionId'] != null && $this['response']['transactionId'] != '') {
                $this['status'] = 'SUCCESS';
                if (isset($this['response']['pinDetail']))
                    $this['pin'] = $this['response']['pinDetail'];
            } else {
                $this['status'] = 'FAIL';
            }
            $this->save();
            if ($sendResponse)
                return $this['status'];
        }
    }
}
