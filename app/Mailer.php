<?php
declare(strict_types=1);
namespace ScreenPort;

final class Mailer
{
    public function __construct(private Config $config,private Settings $settings) {}
    public static function bytes(int $n): string { return number_format($n/1024**3,2).' GiB'; }
    public static function eta(?int $seconds): string
    {
        if($seconds===null || $seconds<0 || $seconds>=8640000) return 'Not available yet';
        if($seconds===0) return 'Complete';
        if($seconds<60) return 'Less than a minute';
        if($seconds<3600) return ceil($seconds/60).' minutes';
        return round($seconds/3600,1).' hours';
    }
    public static function content(array $media,array $torrents,bool $manager): array
    {
        $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $title=$media['title']; $plain=$title."\n".($media['type']==='movie' ? 'Movie' : 'TV show')." · ".$media['year']." · ".$media['rating']."\nRelease date: ".$media['release_date']."\n";
        $html='<div style="background:#10131b;color:#f6f7fa;padding:32px;font-family:Arial,sans-serif;max-width:620px"><p style="color:#8decbd;letter-spacing:3px">SCREENPORT</p><h1>'.$esc($title).'</h1>';
        if(!empty($media['poster']) && preg_match('~^https://image\.tmdb\.org/t/p/w500/[A-Za-z0-9._-]+$~',$media['poster'])) $html.='<img src="'.$esc($media['poster']).'" width="160" alt="'.$esc($title).' cover" style="border-radius:10px">';
        $html.='<p>'.$esc(($media['type']==='movie' ? 'Movie' : 'TV show').' · '.$media['year'].' · '.$media['rating']).'</p><p>Release date: '.$esc($media['release_date']).'<br>Home release: '.$esc($media['home_release'] ?? 'Unknown').'</p><p>'.$esc($media['overview']).'</p>';
        foreach($torrents as $t) {
            $s=json_decode($t['snapshot'],true) ?: [];
            $progress=round(100*(float)($s['progress'] ?? 0));
            $eta=($s['progress'] ?? 0)>=1 ? 'Complete' : self::eta(isset($s['eta']) && $s['eta']>0 ? (int)$s['eta'] : null);
            $seasons=json_decode($t['seasons'],true) ?: [];
            $label=$seasons ? 'Season'.(count($seasons)>1 ? 's ' : ' ').implode(', ',$seasons) : 'Movie';
            $plain.="\n$label: $progress% downloaded\nEstimated remaining time: $eta\n";
            $html.='<div style="padding:16px;background:#1b202c;border-radius:10px;margin:16px 0"><strong>'.$esc($label).'</strong><p>'.$progress.'% downloaded<br>Estimated remaining time: '.$esc($eta).'</p>';
            if($manager) {
                $details=['Torrent'=>$t['name'],'Size'=>self::bytes((int)($s['size'] ?? 0)),'Download speed'=>number_format((int)($s['dlspeed'] ?? 0)/1024**2,2).' MiB/s',
                    'Seeders'=>(string)($s['num_seeds'] ?? 0),'Peers'=>(string)($s['num_leechs'] ?? 0),'State'=>$s['state'] ?? $t['state'],'Destination'=>$t['save_path']];
                foreach($details as $k=>$v) { $plain.=$k.': '.$v."\n"; $html.='<p style="margin:5px 0">'.$esc($k).': '.$esc($v).'</p>'; }
            }
            $html.='</div>';
        }
        $html.='<p style="font-size:12px;color:#a8acb6">Requested through ScreenPort. Estimates change as peers connect.</p></div>';
        return ['subject'=>'ScreenPort download update: '.$title,'html'=>$html,'text'=>$plain];
    }
    public static function folderContent(array $media,string $path): array
    {
        $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $title=$media['title'];
        $text="ScreenPort used a new movie destination\n\nMovie: ".$title."\nDestination: ".$path.
            "\n\nThis folder is outside your configured existing movie folder list. Check the Movies library in Jellyfin and add this location if needed.\n".
            "qBittorrent was asked to save here; ScreenPort cannot inspect the remote filesystem. This alert is sent once per destination, separately from download updates.\n";
        return ['subject'=>'ScreenPort: new movie folder — check Jellyfin',
            'text'=>$text,'html'=>'<div style="font-family:Arial,sans-serif;max-width:620px"><h1>New movie folder</h1><p>Movie: '.$esc($title).
            '</p><p>Destination: <strong>'.$esc($path).'</strong></p><p>This destination is outside your existing movie folder list. Check the Movies library in Jellyfin and add this location if needed.</p><p>qBittorrent was asked to save here; ScreenPort cannot inspect the remote filesystem. This alert is sent once per destination, separately from download updates.</p></div>'];
    }
    private static function managerContent(string $subject,string $heading,array $details,string $action,string $site,bool $admin=true): array
    {
        $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $text=$heading."\n\n"; $html='<div style="font-family:Arial,sans-serif;max-width:620px"><h1>'.$esc($heading).'</h1>';
        foreach($details as $label=>$value) { $text.=$label.': '.$value."\n"; $html.='<p><strong>'.$esc($label).':</strong> '.$esc($value).'</p>'; }
        $text.="\n".$action."\n"; $html.='<p>'.$esc($action).'</p>';
        $url=parse_url($site);
        if($url && in_array($url['scheme'] ?? '',['https','http'],true) && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']) && !preg_match('/[\x00-\x20\x7f]/',$site)) {
            $text.="Open ScreenPort: ".$site."\n";
            $html.='<p><a href="'.$esc($site).'">Open ScreenPort</a> ('.($admin ? 'admin ' : '').'sign-in required)</p>';
        }
        return ['subject'=>$subject,'text'=>$text,'html'=>$html.'</div>'];
    }
    public static function accountContent(?array $user,string $site): array
    {
        if(!$user) throw new \RuntimeException('The requested account is unavailable.');
        return self::managerContent('ScreenPort: account approval requested','New account request',
            ['Username'=>$user['username'],'Email'=>$user['email']],
            'Open Administration → Accounts to review this request. The account requires approval before sign-in.',$site);
    }
    public static function reviewContent(array $media,array $library,array $user,int $review,bool $manager,string $site): array
    {
        $details=['Media'=>$media['title'],'Type'=>$media['type']==='tv' ? 'TV show' : 'Movie','Year'=>$media['year'] ?? '',
            'Content rating'=>$media['rating'] ?? 'Unknown','Release date'=>$media['release_date'] ?? '', 'TMDB ID'=>(string)$media['id'],
            'Review ID'=>(string)$review,'Jellyfin detection'=>'On Jellyfin!','Detected title'=>$library['name'] ?? $media['title']];
        if($manager) $details+=['Requester username'=>$user['username'],'Requester email'=>$user['email'],'Requester user ID'=>(string)$user['id'],'Requester role'=>$user['role']];
        $action=$manager ? 'The requester is still requesting this title despite its Jellyfin match and has asked for a manual review. Check access, missing seasons or episodes, and the library match. Contact the requester using the email above if needed. The review has been requested; no additional download has been started.'
            : 'Your manual review has been requested. ScreenPort detected this title on Jellyfin, but you reported that it may be missing or inaccessible. Your review is queued for the download manager, who can contact you at your account email. No additional download has been started.';
        if($media['type']==='tv') $action.=' A series match does not confirm that every season or episode is present.';
        $content=self::managerContent($manager ? 'ScreenPort: Jellyfin title review requested' : 'ScreenPort: your review request was received',
            $manager ? 'Jellyfin title needs manual review' : 'Your review request was received',$details,$action,$site,$manager);
        if(!empty($media['poster']) && preg_match('~^https://image\.tmdb\.org/t/p/w500/[A-Za-z0-9._-]+$~',$media['poster'])) {
            $content['html']=str_replace('</div>','<p><img src="'.htmlspecialchars($media['poster'],ENT_QUOTES,'UTF-8').'" width="160" alt="Media cover"></p></div>',$content['html']);
        }
        return $content;
    }
    public static function failureContent(array $payload,?int $request,string $username,string $site): array
    {
        $details=['Media'=>$payload['title'],'Type'=>$payload['type'],'TMDB ID'=>(string)$payload['media_id'],'Stage'=>$payload['stage'],'Reason'=>$payload['reason']];
        if($request) $details['Request ID']=(string)$request;
        if($username!=='') $details['Requested by']=$username;
        if(isset($payload['attempt'])) $details['Failed attempt']=(string)$payload['attempt'];
        $terminal=(bool)$payload['terminal'];
        $action=$terminal ? ($request ? 'Automatic retries have stopped. Open Downloads → Search log to review the failure, then retry the request after fixing it.' : 'This submission was not queued. Review the reported reason; the user can submit again after it is resolved.')
            : 'ScreenPort will retry automatically. This first-failure alert is sent once per request retry cycle; a final alert follows if automatic retries are exhausted.';
        $details['Status']=$terminal ? 'Admin review required' : 'Retrying automatically';
        if(!empty($payload['monitoring'])) {
            $details['Status']='Download needs attention';
            $action='Open Downloads and inspect the torrent in qBittorrent. The status is still monitored automatically; this alert does not stop or resubmit the torrent.';
        }
        return self::managerContent('ScreenPort: download failed to start'.($terminal ? ' — review required' : ' — retrying'),'Download failed to start',$details,$action,$site);
    }
    public function send(string $email,array $content): void
    {
        if($this->config->demo()) throw new \RuntimeException('Email is disabled in preview mode.');
        if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) throw new \RuntimeException('Install the mail dependency before enabling notifications.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Invalid notification email.');
        $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host=$this->settings->get('SMTP_HOST'); $mail->Port=(int)$this->settings->get('SMTP_PORT');
        $mail->SMTPAuth=$this->settings->get('SMTP_USERNAME')!=='';
        $mail->Username=$this->settings->get('SMTP_USERNAME'); $mail->Password=$this->settings->get('SMTP_PASSWORD');
        $mail->SMTPSecure=$this->settings->get('SMTP_ENCRYPTION')==='ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS=true; $mail->Timeout=20; $mail->SMTPDebug=0; $mail->CharSet='UTF-8';
        $mail->setFrom($this->settings->get('MAIL_FROM_ADDRESS'),$this->settings->get('MAIL_FROM_NAME'));
        $mail->addAddress($email); $mail->isHTML(true);
        $mail->Subject=$content['subject']; $mail->Body=$content['html']; $mail->AltBody=$content['text'];
        try { $mail->send(); } catch(\Throwable $e) { throw new \RuntimeException('Email delivery failed. Check SMTP connectivity, authentication, and sender permissions.'); }
    }
}
