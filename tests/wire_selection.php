<?php
declare(strict_types=1);
// All external requests are intercepted; this regression suite never uses real credentials.
use ScreenPort\{CandidateEvidence,Catalog,Config,Http,Selector,TorrentPolicy};
$root=dirname(__DIR__); $private=$root.'/.test-output/wire-'.bin2hex(random_bytes(4)); mkdir($private,0700,true);
putenv('SCREENPORT_ENV_FILE='.$private.'/.env'); putenv('APP_ENV=local'); putenv('APP_URL=http://127.0.0.1:8098');
putenv('APP_KEY='.base64_encode(random_bytes(32))); putenv('STORAGE_PATH='.$private);
file_put_contents($private.'/.env',"OPENAI_API_KEY=fake-secret\nOPENAI_MODEL=gpt-4o-mini\nTMDB_READ_ACCESS_TOKEN=fake\n");
require $root.'/app/bootstrap.php';
$checks=0;
function verify(bool $ok,string $message): void { global $checks; $checks++; if(!$ok) throw new RuntimeException('FAIL: '.$message); }
function rejected(callable $fn,string $message): void { try { $fn(); } catch(Throwable $e) { verify(true,$message); return; } verify(false,$message); }
$wire=['type'=>'tv','title'=>'The Wire','original_title'=>'The Wire','original_language'=>'en','year'=>'2002','runtime'=>60,'status'=>'Ended',
    'seasons'=>array_map(fn($n)=>['season_number'=>$n],range(1,5))];
$seasons=[]; foreach([13,12,12,13,10] as $i=>$episodes) $seasons[]=['number'=>$i+1,'episodes'=>$episodes,'runtime'=>60];
$data=[
    ['The Wire Season 1, 2, 3, 4 & 5 Complete Collection DVD Box Set H',25.86,99],
    ['The Wire Season 1 Complete - DVDRip - x264 - MKV by RiddlerA',2.22,79],
    ['The.Wire.Season.S01-S05.Complete.REMASTERED.720p.WEB-DL.2CH.x265',15.32,57],
    ['The.Wire.Season.01.S01.Complete.REMASTERED.720p.WEB-DL.2CH.x265.',3.36,20],
    ['The.Wire.S01.1080p.BluRay.x264-ROVERS [Season 1 One Complete]',56.09,19],
    ['The.Wire.S01-S05.COMPLETE.SERIES.1080p.Bluray.x265-HiQVE',108.64,15],
    ['The Wire 2002 Complete Series Seasons 1 to 5 1080p WEB x264 [i_c]',198.38,12],
    ['The Wire Season 1-5 S01-S05 COMPLETE SERIES 720p BluRay x264',165.35,10],
    ['THE CORNER (2000): Complete WIRE TV Miniseries: 480p DVDRip x264',5.74,18],
    ['Black & Decker the Complete Guide to Wiring',0.09,10],
    ['Wire in the Blood 2002 Season 3 Complete x264 [i_c]',2.66,9],
    ['The Wire (2002) Seasons 1-5 Complete - 1080p.H265.AAC5.1',23.30,2],
    ['THE WIRE S01-S02-S03-S04-S05 COMPLETE High Quality',48.06,1],
];
// Enough higher-seeded unrelated results to exhaust the old 30-result cap.
for($i=0;$i<35;$i++) $data[]=['Wire in the Blood Season 1 Complete 1080p release '.$i,3,50];
$results=[]; foreach($data as $i=>$d) $results[]=['fileName'=>$d[0],'fileSize'=>(int)($d[1]*1024**3),'nbSeeders'=>$d[2],'fileUrl'=>'magnet:?xt=urn:btih:'.str_pad(dechex($i+1),40,'0',STR_PAD_LEFT)];
try {
    $filters=[]; $all=TorrentPolicy::candidates($results,30,$settings,$filters,$wire,$seasons,true);
    verify(count($all)===3,'only full-series packs with supported resolution and sensible size go to model');
    verify(in_array($data[11][0],array_column($all,'name'),true),'lower-seeded compact 1080p full series survives candidate cap');
    verify(($filters['reasons']['Title does not match requested media'] ?? 0)===38,'unrelated books and shows filtered before cap');
    verify($all[0]['evidence']['audio']['basis']==='assumed','unlabeled ordinary releases use approved audio assumption');
    verify($all[0]['evidence']['episode_count']===60,'series size is scaled to all requested episodes');
    $single=TorrentPolicy::candidates($results,30,$settings,$filters,$wire,[$seasons[0]],false);
    verify(count($single)===1,'per-season search keeps exact complete season candidates within size budget');
    verify(($filters['reasons']['Exceeds TV size budget for episode count and runtime'] ?? 0)===1,'56 GiB season exceeds runtime size budget');
    verify($single[0]['evidence']['coverage']['seasons']===[1],'per-season coverage excludes full-series extras');
    foreach(['The Wire Seasons 1 to 5 Complete 1080p','The Wire Season 1, 2, 3, 4 & 5 Complete 1080p','The_Wire_S01-S05_Complete_1080p','The Wire S01-S02-S03-S04-S05 Complete 1080p'] as $name) verify(CandidateEvidence::seasons($name,$wire)['seasons']===[1,2,3,4,5],'common season notation: '.$name);
    verify(!CandidateEvidence::seasons('The Wire S05-S01 Complete 1080p',$wire)['valid'],'descending season ranges fail closed');
    verify(CandidateEvidence::seasons('The Wire S01E01 1080p',$wire)['single_episode'],'episode filenames detected');
    verify(CandidateEvidence::seasons('The Wire 1x01 1080p',$wire)['single_episode'],'alternative episode filenames detected');
    verify(CandidateEvidence::seasons('The Wire S01 E01 1080p',$wire)['single_episode'],'spaced episode filenames detected');
    foreach(['FRENCH','FR','RUS','RU','Hindi Dubbed','MULTI','DUAL AUDIO','Hindi English Subtitles','Malayalam','PT-BR'] as $label) verify(!CandidateEvidence::audio('The Wire S01 Complete 1080p '.$label,$wire,$settings)['allowed'],'foreign/ambiguous audio rejected: '.$label);
    verify(CandidateEvidence::audio('The Wire S01 1080p ENG RUS Dual',$wire,$settings)['allowed'],'original English explicitly included in dual audio');
    verify(CandidateEvidence::audio('The Wire S01 1080p French Subs',$wire,$settings)['basis']==='assumed','foreign subtitles do not imply foreign audio');
    $french=array_replace($wire,['title'=>'French Kiss','original_title'=>'French Kiss']);
    verify(CandidateEvidence::audio('French Kiss 2025 1080p',$french,$settings)['basis']==='assumed','language in movie title is not treated as an audio label');
    $japanese=array_replace($wire,['original_language'=>'ja']);
    verify(!CandidateEvidence::audio('The Wire S01 1080p English Dub',$japanese,$settings)['allowed'],'English-only dub rejected for non-English original');
    verify(CandidateEvidence::audio('The Wire S01 1080p Japanese ENG Subs',$japanese,$settings)['allowed'],'original non-English audio with English subtitles accepted');
    $settings->save(['ASSUME_ORIGINAL_AUDIO'=>'false']);
    verify(!CandidateEvidence::audio('The Wire S01 Complete 1080p',$wire,$settings)['allowed'],'strict policy rejects unlabeled audio');
    verify(!CandidateEvidence::audio('The Wire S01 Complete 1080p ENG Subs',$wire,$settings)['allowed'],'subtitles do not establish English audio in strict mode');
    verify(CandidateEvidence::audio('The Wire S01 Complete 1080p English',$wire,$settings)['allowed'],'strict policy accepts explicit English audio');
    verify(!TorrentPolicy::candidates($results,30,$settings,$filters,$wire,$seasons,true),'strict audio filters excluded candidates logged without calling model');
    $settings->save(['ASSUME_ORIGINAL_AUDIO'=>'true']);
    $modelCalls=0; $contradictions=0;
    Http::setTestTransport(function($method,$url,$headers,$body) use (&$modelCalls,&$contradictions) {
        verify($url==='https://api.openai.com/v1/responses','only fake Responses endpoint used'); $modelCalls++;
        $request=json_decode($body,true); $input=json_decode($request['input'],true);
        verify(str_contains($request['instructions'],'Do not lower confidence or reject it solely'),'missing label is explicitly allowed in prompt');
        verify($request['text']['format']['strict']===true && in_array('evaluations',$request['text']['format']['schema']['required'],true),'per-candidate explanations required by schema');
        verify(!str_contains($request['input'],'fake-secret') && !str_contains($request['input'],'magnet:'),'credentials and torrent links excluded from model input');
        $best=$input['candidates'][0];
        foreach($input['candidates'] as $c) if(str_contains($c['name'],'1080p.H265.AAC5.1')) $best=$c;
        $selection=['candidate_id'=>$best['id'],'seasons'=>array_column($input['wanted_seasons'],'number'),'confidence'=>.95,'correct_title'=>true,'language_ok'=>true,'theater_recording'=>false,'quality'=>$best['evidence']['quality'],'pack'=>$input['all_only'] ? 'all_seasons' : 'season','reason'=>'Matching identity and coverage; original audio assumed under policy.'];
        $evaluations=[];
        foreach($input['candidates'] as $c) $evaluations[]=['candidate_id'=>$c['id'],'verdict'=>$c['id']===$best['id'] ? 'selected' : 'alternative','reason'=>$c['id']===$best['id'] ? 'Sensible full-series 1080p encode.' : 'Eligible alternative; less compelling size/quality tradeoff.'];
        $evaluations[]=['candidate_id'=>'invented','verdict'=>'rejected','reason'=>'must not persist'];
        $picks=[$selection];
        if($contradictions>0) { $contradictions--; $picks=[]; }
        return ['status'=>200,'body'=>json_encode(['status'=>'completed','output'=>[['content'=>[['type'=>'output_text','text'=>json_encode(['selections'=>$picks,'summary'=>'Selected an eligible pack.','evaluations'=>$evaluations])]]]]])];
    });
    $decision=[]; $picked=(new Selector($settings))->select($wire,$all,$seasons,true,$decision);
    verify(count($picked)===1 && str_contains($picked[0]['name'],'1080p.H265.AAC5.1'),'simulated full-series choice passes independent server validation');
    verify(count($decision['evaluations'])===count($all),'every real candidate explanation persisted; unknown ID ignored');
    verify(!str_contains(json_encode($decision),'invented'),'unknown generated IDs excluded from diagnostics');
    $picked=(new Selector($settings))->select($wire,$single,[$seasons[0]],false,$decision);
    verify(count($picked)===1 && $picked[0]['seasons']===[1],'single-season selection passes coverage validation');
    verify($modelCalls===2,'one model request per selection phase');
    $contradictions=1; $beforeCalls=$modelCalls;
    $picked=(new Selector($settings))->select($wire,$all,$seasons,true,$decision);
    verify(count($picked)===1 && $modelCalls===$beforeCalls+2 && $decision['response_attempts']===2,'contradictory selected verdict and empty plan repaired with one bounded correction');
    $contradictions=5; $beforeCalls=$modelCalls;
    rejected(fn()=>(new Selector($settings))->select($wire,$all,$seasons,true,$decision),'repeated contradictory model responses fail closed');
    verify($modelCalls===$beforeCalls+2,'model correction capped at two response attempts');
    $bad=$all[0]; $bad['name'].=' HINDI';
    $selection=['selections'=>[['candidate_id'=>$bad['id'],'seasons'=>[1,2,3,4,5],'confidence'=>.99,'correct_title'=>true,'language_ok'=>true,'theater_recording'=>false,'quality'=>'720p','pack'=>'all_seasons']]];
    rejected(fn()=>TorrentPolicy::validate($selection,[$bad],$wire,[1,2,3,4,5],$settings,true),'server rejects foreign audio despite confident model approval');
    echo "PASS: $checks Wire selection checks. All model responses simulated; no downloads or email.\n";
} catch(Throwable $e) { echo $e->getMessage()."\n"; exit(1); }
finally { Http::setTestTransport(null); }
