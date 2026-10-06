<?php
declare(strict_types=1);

final class UfcEventSync
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function syncCard(int $eventId): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM events WHERE id=?');$stmt->execute([$eventId]);$event=$stmt->fetch();
        if(!$event||trim((string)$event['source_url'])==='') throw new RuntimeException('Dogodek nima uradne UFC povezave.');
        $html=$this->download((string)$event['source_url']);
        $document=new DOMDocument();libxml_use_internal_errors(true);$document->loadHTML($html);libxml_clear_errors();$xpath=new DOMXPath($document);
        $containers=$xpath->query('//div[contains(@class,"c-listing-fight__content")]');
        $written=0;$order=(int)$this->pdo->query('SELECT COALESCE(MAX(card_order),0) FROM fights WHERE event_id='.(int)$eventId)->fetchColumn();
        $insert=$this->pdo->prepare('INSERT INTO fights(event_id,card_order,card_section,weight_class,fighter_a,fighter_b,odds_a,odds_b) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(event_id,fighter_a,fighter_b) DO UPDATE SET weight_class=excluded.weight_class,odds_a=COALESCE(excluded.odds_a,fights.odds_a),odds_b=COALESCE(excluded.odds_b,fights.odds_b)');
        foreach($containers as $container){
            $names=[];foreach($xpath->query('.//*[contains(@class,"c-listing-fight__corner-name")]', $container) as $node){$name=trim((string)preg_replace('/\s+/',' ',$node->textContent));if($name!==''&&!in_array($name,$names,true))$names[]=$name;}
            if(count($names)<2)continue;
            $weightNode=$xpath->query('.//*[contains(@class,"c-listing-fight__class-text")]', $container)->item(0);$weight=$weightNode?trim((string)preg_replace('/\s+/',' ',$weightNode->textContent)):'';
            $odds=[];foreach($xpath->query('.//*[contains(@class,"c-listing-fight__odds-amount")]', $container) as $node){$odds[]=$this->americanToDecimal(trim($node->textContent));}
            $sectionNode=$xpath->query('ancestor::*[contains(@class,"l-listing")][1]/preceding-sibling::*[self::h2 or self::h3][1]', $container)->item(0);$section=$sectionNode?trim($sectionNode->textContent):'Card';
            $insert->execute([$eventId,++$order,$section?:'Card',$weight,$names[0],$names[1],$odds[0]??null,$odds[1]??null]);$written++;
        }
        if($written===0)throw new RuntimeException('UFC stran je dosegljiva, vendar carda ni bilo mogoče razbrati. Dodaj borbe ročno ali poskusi pozneje.');
        return ['event_id'=>$eventId,'fights_written'=>$written,'source'=>$event['source_url']];
    }

    private function download(string $url):string
    {
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>40,CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept: text/html']]);$body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
        if(!is_string($body)||$status>=400||$error!=='')throw new RuntimeException('Uradni UFC card ni dosegljiv (HTTP '.$status.'). '.$error);
        return $body;
    }
    private function americanToDecimal(string $value):?float
    {
        if(!preg_match('/([+-]\d+)/',$value,$m))return null;$american=(int)$m[1];return $american>0?round(1+$american/100,6):round(1+100/abs($american),6);
    }
}
