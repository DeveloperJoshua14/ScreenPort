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
