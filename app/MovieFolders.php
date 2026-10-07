<?php
declare(strict_types=1);
namespace ScreenPort;

final class MovieFolders
{
    public const EXISTING='Action,Adults,Adventure,Divergent,Drama,Harry Potter,Horror,Hunger Games,James Bond,Kids,Marvel Cinematic Universe,Maze Runner,Murder Mysteries,Other,Pirates of the Caribbean,Romance,RomCom,Star Wars,Twilight,X-Men';

    public static function existing(Settings $settings): array
    {
        return array_values(array_unique(array_map('trim',explode(',',$settings->get('MOVIE_EXISTING_FOLDERS')))));
    }
    private static function permitted(string $folder,Settings $settings): bool
    {
        // Never sanitize a custom destination into a different folder silently.
        return preg_match('/^[\pL\pN _-]{1,60}$/u',$folder)===1
            && (in_array($folder,self::existing($settings),true) || $settings->bool('ALLOW_NEW_MOVIE_FOLDERS'));
    }
    public static function folder(array $media,Settings $settings): string
    {
        $overrides=json_decode($settings->get('MOVIE_FOLDER_OVERRIDES'),true) ?: [];
        $override=$overrides[(string)($media['id'] ?? '')] ?? null;
        if(is_string($override) && self::permitted($override,$settings)) return $override;

        $collection=mb_strtolower(trim($media['collection']['name'] ?? ''));
        $collectionFolders=[283579=>'Divergent',1241=>'Harry Potter',131635=>'Hunger Games',645=>'James Bond',
            295130=>'Maze Runner',295=>'Pirates of the Caribbean',10=>'Star Wars',33514=>'Twilight',
            748=>'X-Men',453993=>'X-Men',448150=>'X-Men',531241=>'Marvel Cinematic Universe',623911=>'Marvel Cinematic Universe'];
        $franchises=[
            'divergent collection'=>'Divergent','the divergent collection'=>'Divergent',
            'harry potter collection'=>'Harry Potter',
            'the hunger games collection'=>'Hunger Games','hunger games collection'=>'Hunger Games',
            'james bond collection'=>'James Bond',
            'the maze runner collection'=>'Maze Runner','maze runner collection'=>'Maze Runner',
            'pirates of the caribbean collection'=>'Pirates of the Caribbean',
            'star wars collection'=>'Star Wars',
            'the twilight collection'=>'Twilight','twilight collection'=>'Twilight','the twilight saga collection'=>'Twilight',
            'x-men collection'=>'X-Men','the wolverine collection'=>'X-Men','wolverine collection'=>'X-Men','deadpool collection'=>'X-Men',
        ];
        $folder=$collectionFolders[(int)($media['collection']['id'] ?? 0)] ?? $franchises[$collection] ?? null;
        // The anthology films have no membership in the main Star Wars collection.
        if(in_array((int)($media['id'] ?? 0),[330459,348350],true)) $folder='Star Wars';
        // Deadpool & Wolverine crosses into the MCU; older Deadpool films stay in X-Men.
        if((int)($media['id'] ?? 0)===533535) $folder='Marvel Cinematic Universe';
        $mcuCollections=['the avengers collection','avengers collection','iron man collection','thor collection',
            'captain america collection','guardians of the galaxy collection','ant-man collection',
            'doctor strange collection','black panther collection','captain marvel collection',
            'spider-man (mcu) collection'];
        if(!$folder && in_array($collection,$mcuCollections,true)) $folder='Marvel Cinematic Universe';
        // Company credits alone also include older, non-MCU Marvel movies, so use
        // specific standalone MCU identities instead of treating every Marvel film as MCU.
        if(!$folder && in_array((int)($media['id'] ?? 0),[1726,1724,10138,10195,1771,24428,497698,524434,566525,986056,617126],true)) $folder='Marvel Cinematic Universe';
        if($folder && in_array($folder,self::existing($settings),true)) return $folder;

        if(!empty($media['adult']) || strtoupper(trim($media['rating'] ?? ''))==='NC-17') $folder='Adults';
        else {
            $genres=$media['genres'] ?? [];
            $map=json_decode($settings->get('MOVIE_GENRE_MAP'),true) ?: [];
            foreach($genres as $genre) {
                if(isset($map[$genre]) && self::permitted($map[$genre],$settings)) return $map[$genre];
            }
            $rating=strtoupper(trim($media['rating'] ?? ''));
            if(in_array($rating,['G','PG'],true) && array_intersect($genres,['Family','Animation'])) $folder='Kids';
            elseif(in_array('Romance',$genres,true)) $folder=in_array('Comedy',$genres,true) ? 'RomCom' : 'Romance';
            elseif(in_array('Horror',$genres,true)) $folder='Horror';
            elseif(in_array('Mystery',$genres,true) || in_array('Crime',$genres,true)) $folder='Murder Mysteries';
            else {
                $folder='Other';
                foreach(['Action','Adventure','Drama'] as $genre) if(in_array($genre,$genres,true)) { $folder=$genre; break; }
            }
        }
        return in_array($folder,self::existing($settings),true) ? $folder : 'Other';
    }
    public static function newPath(string $path,Settings $settings): bool
    {
        $root=rtrim($settings->get('MOVIE_ROOT'),'/').'/';
        if(!str_starts_with($path,$root)) return false;
        $folder=rtrim(substr($path,strlen($root)),'/');
        // Reject nested paths and never interpret a TV destination as a movie folder.
        return $folder!=='' && !str_contains($folder,'/') && !in_array($folder,self::existing($settings),true);
    }
}
