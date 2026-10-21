<?php

namespace App\Ai\Tools;

use App\Services\Dj\SetValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** The DJ agent's only tool. Every call goes through SetValidator; the agent never writes reasons. */
class AddTrack implements Tool
{
    public function __construct(private SetValidator $validator) {}

    public function description(): Stringable|string
    {
        return 'Add one track to the set. The server checks it against the stored Qloo results and writes the public '
            .'reason itself. If it is rejected, read the error, fix the call and try again.';
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->validator->add($request->all()), JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'artist' => $schema->string()->description('Artist exactly as listed in that love\'s results.')->required(),
            'title' => $schema->string()->description('One of that artist\'s listed songs.')->required(),
            'for_guest' => $schema->integer()->description('Guest id this track is for.')->required(),
            'love' => $schema->integer()->description('Love id whose results list this artist.')->required(),
            'energy' => $schema->integer()->description('Your estimate, 1 (calm) to 5 (peak).')->required(),
            'bpm' => $schema->integer()->description('Your estimate of the tempo.')->required(),
        ];
    }
}
