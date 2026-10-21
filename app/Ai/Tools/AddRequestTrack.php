<?php

namespace App\Ai\Tools;

use App\Services\Dj\RequestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** The request DJ's only tool. Every call goes through RequestValidator. */
class AddRequestTrack implements Tool
{
    public function __construct(private RequestValidator $validator) {}

    public function description(): Stringable|string
    {
        return 'Add one track for the host\'s request, for one guest. The server checks it against that guest\'s '
            .'filtered Qloo results and writes the reason itself. If it is rejected, fix the call and try again.';
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->validator->add($request->all()), JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'artist' => $schema->string()->description('Artist exactly as listed in that guest\'s results.')->required(),
            'title' => $schema->string()->description('One of that artist\'s listed songs.')->required(),
            'for_guest' => $schema->integer()->description('Guest id whose results list this artist.')->required(),
            'energy' => $schema->integer()->description('Your estimate, 1 (calm) to 5 (peak).')->required(),
            'bpm' => $schema->integer()->description('Your estimate of the tempo.')->required(),
        ];
    }
}
