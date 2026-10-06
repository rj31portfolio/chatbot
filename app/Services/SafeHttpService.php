<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Illuminate\Validation\ValidationException;

class SafeHttpService
{
    public function resolve(string $url): array
    {
        $parts=parse_url($url);
        $host=strtolower($parts['host']??'');
        if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array($parts['scheme']??'',['http','https'],true)||isset($parts['user'])||isset($parts['pass'])||!$host||isset($parts['port'])&&!in_array($parts['port'],[80,443],true)) $this->reject();
        if(filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)) $ips=[trim($host,'[]')];
        else {
            $records=@dns_get_record($host,DNS_A|DNS_AAAA)?:[];
            $ips=array_values(array_filter(array_map(fn($r)=>$r['ip']??$r['ipv6']??null,$records)));
        }
        if(!$ips) $this->reject();
        foreach($ips as $ip) {
            if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)||str_starts_with(strtolower($ip),'::ffff:')) $this->reject();
        }
        return [$host,$parts['port']??($parts['scheme']==='https'?443:80),$ips[0]];
    }
    private function reject(): never { throw ValidationException::withMessages(['url'=>'Only public HTTP/HTTPS websites on ports 80 or 443 are allowed.']); }
    public function fetch(string $url,string $method='GET',?array $payload=null,array $headers=[]): Response
    {
        [$host,$port,$ip]=$this->resolve($url);
        $request=Http::withHeaders(array_merge(['User-Agent'=>'AILeadAgentBot/1.0','Accept'=>'text/html,text/plain,application/json'],$headers))->timeout(15)->connectTimeout(5)->withOptions(['allow_redirects'=>false,'curl'=>[CURLOPT_RESOLVE=>["{$host}:{$port}:{$ip}"]],'on_headers'=>function($response){ if((int)$response->getHeaderLine('Content-Length')>2*1024*1024) throw new \RuntimeException('Response too large.'); },'progress'=>function($total,$downloaded){ if($downloaded>2*1024*1024) throw new \RuntimeException('Response too large.'); }]);
        return $method==='POST'?$request->post($url,$payload??[]):$request->get($url);
    }
}
