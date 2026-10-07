<?php
declare(strict_types=1);
namespace ScreenPort;

final class LibraryReviews
{
    public function __construct(private Config $config,private Db $db,private Settings $settings,private Catalog $catalog) {}
    public function request(array $user,string $type,int $id): array
    {
        if(!in_array($type,['movie','tv'],true) || $id<1) throw new ApiError('Invalid media request.',422);
        if($this->config->demo()) throw new ApiError('Review requests are disabled in preview.',403);
        $message='Review requested. Confirmation emails are queued for you and the download manager.';
        $existing=$this->recent((int)$user['id'],$type,$id);
        if($existing) return ['id'=>(int)$existing['id'],'message'=>'You already requested a review of this title in the last 24 hours.'];
        if(!filter_var($this->settings->get('DOWNLOAD_MANAGER_EMAIL'),FILTER_VALIDATE_EMAIL)) throw new ApiError('An admin must configure the download manager email before reviews can be requested.',503);
        $this->db->limit('library-review:'.$user['id'],(int)$this->settings->get('REQUEST_LIMIT_PER_DAY'),86400);
        $media=$this->catalog->detail($type,$id);
        $library=(new Jellyfin($this->settings,$this->db))->find($media,true);
        if(!$library) throw new ApiError('This title is no longer detected on Jellyfin. Reopen it to check availability and request a download.',409);
        $safe=(new SearchLog($this->db,$this->settings))->redact(array_intersect_key($media,array_flip(['id','type','title','year','rating','release_date','overview'])));
        $safe['poster']=!empty($media['poster']) && preg_match('~^https://image\.tmdb\.org/t/p/w500/[A-Za-z0-9._-]+$~',$media['poster']) ? $media['poster'] : null;
        $found=(new SearchLog($this->db,$this->settings))->redact(['in_library'=>true,'name'=>$library['name'],'type'=>$library['type']]);
        return $this->db->transaction(function() use($user,$type,$id,$safe,$found,$message) {
            // Recheck while holding the database write lock to handle double clicks or parallel submissions.
            $existing=$this->recent((int)$user['id'],$type,$id);
            if($existing) return ['id'=>(int)$existing['id'],'message'=>'You already requested a review of this title in the last 24 hours.'];
            $this->db->run('INSERT INTO library_reviews(user_id,media_type,media_id,media,library,created_at) VALUES(?,?,?,?,?,?)',[$user['id'],$type,$id,json_encode($safe,JSON_THROW_ON_ERROR),json_encode($found,JSON_THROW_ON_ERROR),time()]);
            $review=$this->db->id();
            (new ManagerAlerts($this->config,$this->db,$this->settings))->review($review,(int)$user['id']);
            $this->db->audit((int)$user['id'],'requested_library_review',$type.':'.$id.'; review '.$review);
            return ['id'=>$review,'message'=>$message];
        });
    }
    private function recent(int $user,string $type,int $id): ?array
    {
        return $this->db->one('SELECT id FROM library_reviews WHERE user_id=? AND media_type=? AND media_id=? AND created_at>=? ORDER BY id DESC LIMIT 1',[$user,$type,$id,time()-86400]);
    }
}
