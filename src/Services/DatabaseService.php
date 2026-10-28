<?php

declare(strict_types=1);

namespace SqlCanvas\Services;

use SqlCanvas\Database\DatabaseSession;
use SqlCanvas\Database\MetadataReader;
use SqlCanvas\Database\SqlIdentifier;
use SqlCanvas\Http\ApiException;

final readonly class DatabaseService
{
    public function __construct(private DatabaseSession $database, private MetadataReader $metadata, private bool $readOnly)
    {
    }

    /** @return array<string,mixed> */
    public function statistics(string $schema): array
    {
        $pdo=$this->database->pdo();$objects=$this->metadata->schemaObjects($pdo,$schema);
        $statement=$pdo->prepare('SELECT TABLE_NAME AS name, TABLE_TYPE AS type, ENGINE AS engine, TABLE_ROWS AS estimatedRows, DATA_LENGTH AS dataBytes, INDEX_LENGTH AS indexBytes, TABLE_COLLATION AS collation FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema ORDER BY TABLE_NAME');
        $statement->execute(['schema'=>$schema]);$tables=$statement->fetchAll();
        return ['database'=>$schema,'tableCount'=>count($objects['tables']),'viewCount'=>count($objects['views']),'routineCount'=>count($objects['routines']),'triggerCount'=>count($objects['triggers']),'eventCount'=>count($objects['events']),'estimatedRows'=>array_sum(array_map(static fn(array $row):int=>(int)($row['estimatedRows']??0),$tables)),'dataBytes'=>array_sum(array_map(static fn(array $row):int=>(int)($row['dataBytes']??0),$tables)),'indexBytes'=>array_sum(array_map(static fn(array $row):int=>(int)($row['indexBytes']??0),$tables)),'tables'=>$tables];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function previewCopy(array $input): array
    {
        $source=$this->identifier($input['source']??null,'source database');$target=$this->identifier($input['target']??null,'target database');if($source===$target)throw new ApiException('invalid_copy_target','Choose a different target database.',422);
        $pdo=$this->database->pdo();$objects=$this->metadata->schemaObjects($pdo,$source);if(in_array($target,array_column($this->metadata->databases($pdo),'name'),true))throw new ApiException('copy_target_exists','The target database already exists.',409);
        $includeData=($input['includeData']??false)===true;$charset=$this->word($input['charset']??'utf8mb4','character set');$collation=$this->word($input['collation']??'utf8mb4_unicode_ci','collation');
        $steps=1+count($objects['tables'])*($includeData?2:1);$warnings=['This workflow creates a new database and copies base-table structures'.($includeData?' and rows.':'.')];if($objects['views']!==[]||$objects['routines']!==[]||$objects['triggers']!==[]||$objects['events']!==[])$warnings[]='Views, routines, triggers, and events are not copied because definers and cross-schema references require manual review.';if($includeData)$warnings[]='Large tables can hold locks or exceed the browser request timeout. Use native dump tools for large databases.';
        return ['source'=>$source,'target'=>$target,'includeData'=>$includeData,'charset'=>$charset,'collation'=>$collation,'tableCount'=>count($objects['tables']),'estimatedSteps'=>$steps,'confirmation'=>"COPY {$source} TO {$target}",'warnings'=>$warnings,'sql'=>sprintf('CREATE DATABASE %s CHARACTER SET %s COLLATE %s; -- then CREATE TABLE ... LIKE ...%s',SqlIdentifier::quote($target),$charset,$collation,$includeData?' and INSERT ... SELECT ...':'')];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function copy(array $input): array
    {
        if($this->readOnly)throw new ApiException('read_only_policy','This SQL Canvas deployment is configured for read-only database access.',403);$preview=$this->previewCopy($input);if(($input['confirmation']??null)!==$preview['confirmation'])throw new ApiException('confirmation_required','Type the exact source and target database confirmation.',422);
        $pdo=$this->database->pdo();$objects=$this->metadata->schemaObjects($pdo,$preview['source']);$created=false;$copied=0;
        try{$pdo->exec(sprintf('CREATE DATABASE %s CHARACTER SET %s COLLATE %s',SqlIdentifier::quote($preview['target']),$preview['charset'],$preview['collation']));$created=true;foreach($objects['tables'] as $table){$name=(string)$table['name'];$source=SqlIdentifier::qualified($preview['source'],$name);$target=SqlIdentifier::qualified($preview['target'],$name);$pdo->exec("CREATE TABLE {$target} LIKE {$source}");if($preview['includeData'])$pdo->exec("INSERT INTO {$target} SELECT * FROM {$source}");$copied++;}}
        catch(\Throwable $exception){if($created){try{$pdo->exec('DROP DATABASE '.SqlIdentifier::quote($preview['target']));}catch(\Throwable){}}throw $exception;}
        return ['source'=>$preview['source'],'target'=>$preview['target'],'tablesCopied'=>$copied,'dataCopied'=>$preview['includeData'],'omittedObjects'=>['views'=>count($objects['views']),'routines'=>count($objects['routines']),'triggers'=>count($objects['triggers']),'events'=>count($objects['events'])]];
    }

    private function identifier(mixed $value,string $label):string{$value=trim(is_string($value)?$value:'');if($value===''||strlen($value)>64||str_contains($value,"\0"))throw new ApiException('invalid_identifier',"Enter a valid {$label} identifier.",422);return $value;}
    private function word(mixed $value,string $label):string{$value=trim(is_string($value)?$value:'');if($value===''||preg_match('/\A[A-Za-z0-9_]+\z/',$value)!==1)throw new ApiException('validation_failed',"Enter a valid {$label}.",422);return $value;}
}
