<?php

it('reports a healthy API', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'service' => 'Starfall Station API',
        ]);
});
