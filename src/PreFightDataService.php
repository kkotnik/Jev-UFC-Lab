<?php
declare(strict_types=1);

final class PreFightDataService
{
    private const DATA_FILE = __DIR__ . '/../data/ultimate_ufc_dataset.csv';
    private ?array $dataset = null;

    public function __construct(private readonly Database $database) {}

    public function refreshEvent(int $eventId): array
    {
        $stmt=$this->database->pdo()->prepare('SELECT id FROM fights WHERE event_id=? ORDER BY card_order');
        $stmt->execute([$eventId]);
        $updated=0;$quality=[];
        foreach($stmt->fetchAll() as $row){$data=$this->refreshFight((int)$row['id']);$updated++;$quality[]=(float)$data['overall_quality'];}
        return ['event_id'=>$eventId,'fights_updated'=>$updated,'average_quality'=>$quality?array_sum($quality)/count($quality):0];
    }

    public function refreshFight(int $fightId): array
    {
        $stmt=$this->database->pdo()->prepare('SELECT f.*,e.event_date,e.venue,e.name event_name FROM fights f JOIN events e ON e.id=f.event_id WHERE f.id=?');
        $stmt->execute([$fightId]);$fight=$stmt->fetch();
        if(!$fight)throw new InvalidArgumentException('Borba ne obstaja.');
        $before=substr((string)$fight['event_date'],0,10);
        $a=$this->fighterSnapshot((string)$fight['fighter_a'],$before);
        $b=$this->fighterSnapshot((string)$fight['fighter_b'],$before);
        $context=$this->eventContext((string)$fight['venue'],(string)$fight['weight_class'],$before);
        $overall=round(((float)$a['data_quality']+(float)$b['data_quality'])/2,3);
        $source='TidyTuesday ultimate_ufc_dataset pre-fight snapshots + local UFC fight history; injuries, weight misses and short-notice status remain unknown unless verified.';
        $upsert=$this->database->pdo()->prepare('INSERT INTO fight_prefight_data(fight_id,snapshot_date,fighter_a_json,fighter_b_json,event_context_json,source_summary,updated_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(fight_id) DO UPDATE SET snapshot_date=excluded.snapshot_date,fighter_a_json=excluded.fighter_a_json,fighter_b_json=excluded.fighter_b_json,event_context_json=excluded.event_context_json,source_summary=excluded.source_summary,updated_at=CURRENT_TIMESTAMP');
        $upsert->execute([$fightId,$before,json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($b,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$source]);
        return ['fight_id'=>$fightId,'snapshot_date'=>$before,'fighter_a'=>$a,'fighter_b'=>$b,'event_context'=>$context,'overall_quality'=>$overall,'source_summary'=>$source];
    }

    public function getFightData(int $fightId, bool $refreshIfMissing=true): ?array
    {
        $stmt=$this->database->pdo()->prepare('SELECT * FROM fight_prefight_data WHERE fight_id=?');$stmt->execute([$fightId]);$row=$stmt->fetch();
        if(!$row)return $refreshIfMissing?$this->refreshFight($fightId):null;
        $a=json_decode($row['fighter_a_json'],true)?:[];$b=json_decode($row['fighter_b_json'],true)?:[];
        return ['fight_id'=>$fightId,'snapshot_date'=>$row['snapshot_date'],'fighter_a'=>$a,'fighter_b'=>$b,'event_context'=>json_decode($row['event_context_json'],true)?:[],'overall_quality'=>round(((float)($a['data_quality']??0)+(float)($b['data_quality']??0))/2,3),'source_summary'=>$row['source_summary'],'updated_at'=>$row['updated_at']];
    }

    private function fighterSnapshot(string $name,string $before): array
    {
        $profile=$this->latestDatasetProfile($name,$before);
        $activity=$this->activity($name,$before);
        $eventTs=strtotime($before);$snapshotTs=$profile?strtotime($profile['date']):false;
        $age=$profile&&$profile['age']!==null?(float)$profile['age']+(($eventTs-$snapshotTs)/31557600):null;
        $available=0;$possible=8;
        foreach([$age,$profile['height_cm']??null,$profile['reach_cm']??null,$profile['stance']??null,$activity['sample_size'],$activity['days_since_last_fight'],$profile['wins']??null,$profile['losses']??null] as $value)if($value!==null&&$value!=='')$available++;
        return [
            'name'=>$name,'age_at_event'=>$age===null?null:round($age,1),'height_cm'=>$profile['height_cm']??null,'reach_cm'=>$profile['reach_cm']??null,'stance'=>$profile['stance']??null,
            'record_before_event'=>['wins'=>$profile['wins']??$activity['wins'],'losses'=>$profile['losses']??$activity['losses'],'draws'=>$profile['draws']??null],
            'current_win_streak'=>$profile['win_streak']??null,'current_loss_streak'=>$profile['loss_streak']??null,
            'striking'=>['avg_significant_strikes'=>$profile['sig_str']??null,'accuracy'=>$profile['sig_pct']??null],
            'grappling'=>['avg_takedowns'=>$profile['td']??null,'takedown_accuracy'=>$profile['td_pct']??null,'avg_submission_attempts'=>$profile['sub']??null],
            'activity'=>$activity,'data_quality'=>round($available/$possible,3),'profile_snapshot_date'=>$profile['date']??null,
            'verification_flags'=>['injury_status'=>'unknown','short_notice'=>'unknown','replacement_opponent'=>'unknown','weight_miss'=>'unknown'],
        ];
    }

    private function activity(string $name,string $before): array
    {
        $stmt=$this->database->pdo()->prepare('SELECT * FROM historical_fights WHERE (fighter_a=:n OR fighter_b=:n) AND event_date<:d ORDER BY event_date DESC LIMIT 20');
        $stmt->execute(['n'=>$name,'d'=>$before]);$rows=$stmt->fetchAll();$wins=$losses=$last365=$last730=$finishWins=0;
        $beforeTs=strtotime($before);
        foreach($rows as $row){$won=$row['winner']===$name;$wins+=(int)$won;$losses+=(int)(!$won&&$row['winner']!==null);$days=(int)floor(($beforeTs-strtotime($row['event_date']))/86400);$last365+=(int)($days<=365);$last730+=(int)($days<=730);$finishWins+=(int)($won&&!str_contains(strtolower((string)$row['method']),'decision'));}
        $lastDate=$rows[0]['event_date']??null;
        return ['sample_size'=>count($rows),'wins'=>$wins,'losses'=>$losses,'last_fight_date'=>$lastDate,'days_since_last_fight'=>$lastDate?(int)floor(($beforeTs-strtotime($lastDate))/86400):null,'fights_last_365_days'=>$last365,'fights_last_730_days'=>$last730,'finish_wins_in_sample'=>$finishWins];
    }

    private function latestDatasetProfile(string $name,string $before): ?array
    {
        $data=$this->loadDataset();$key=$this->normalize($name);$best=null;
        foreach($data as $row){if($row['date']>=$before)continue;foreach(['r','b'] as $corner){if($this->normalize((string)$row[$corner.'_fighter'])!==$key)continue;if($best!==null&&$best['date']>=$row['date'])continue;$value=static fn(string $field):mixed=>isset($row[$field])&&$row[$field]!==''&&$row[$field]!=='NA'?(is_numeric($row[$field])?(float)$row[$field]:$row[$field]):null;$best=['date'=>$row['date'],'age'=>$value($corner.'_age'),'height_cm'=>$value($corner.'_height_cms'),'reach_cm'=>$value($corner.'_reach_cms'),'stance'=>$value($corner.'_stance'),'wins'=>$value($corner.'_wins'),'losses'=>$value($corner.'_losses'),'draws'=>$value($corner.'_draw'),'win_streak'=>$value($corner.'_current_win_streak'),'loss_streak'=>$value($corner.'_current_lose_streak'),'sig_str'=>$value($corner.'_avg_sig_str_landed'),'sig_pct'=>$value($corner.'_avg_sig_str_pct'),'td'=>$value($corner.'_avg_td_landed'),'td_pct'=>$value($corner.'_avg_td_pct'),'sub'=>$value($corner.'_avg_sub_att')];}}
        return $best;
    }

    private function loadDataset(): array
    {
        if($this->dataset!==null)return $this->dataset;
        if(!is_file(self::DATA_FILE))return $this->dataset=[];
        $handle=fopen(self::DATA_FILE,'rb');if($handle===false)return $this->dataset=[];
        $header=fgetcsv($handle,0,',','"','\\');$rows=[];
        while(($values=fgetcsv($handle,0,',','"','\\'))!==false){if(count($values)===count($header))$rows[]=array_combine($header,$values);}fclose($handle);
        return $this->dataset=$rows;
    }

    private function eventContext(string $venue,string $weightClass,string $date): array
    {
        $lower=strtolower($venue);$altitude=null;
        foreach(['las vegas'=>610,'edmonton'=>645,'abu dhabi'=>27,'mexico city'=>2240,'denver'=>1609,'salt lake city'=>1288] as $place=>$meters)if(str_contains($lower,$place)){$altitude=$meters;break;}
        return ['event_date'=>$date,'venue'=>$venue,'altitude_m'=>$altitude,'altitude_band'=>$altitude===null?'unknown':($altitude>=1500?'high':($altitude>=700?'moderate':'low')),'weight_class'=>$weightClass,'travel_load'=>'unknown'];
    }

    private function normalize(string $name): string {return strtolower(preg_replace('/[^a-z0-9]+/i','',iconv('UTF-8','ASCII//TRANSLIT',$name)?:$name));}
}
