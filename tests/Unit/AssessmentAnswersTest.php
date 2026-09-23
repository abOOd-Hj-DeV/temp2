<?php

namespace Tests\Unit;

use App\Models\Assessment;
use Tests\TestCase;

class AssessmentAnswersTest extends TestCase
{
    public function test_answers_are_stored_as_ciphertext_only_and_round_trip(): void
    {
        $assessment = new Assessment;
        $assessment->answers = ['q1' => 3, 'q2' => 0];

        $raw = json_decode($assessment->getAttributes()['answers'], true);

        $this->assertSame(['encrypted'], array_keys($raw['q1']));
        $this->assertStringNotContainsString(hash('sha256', '3'), json_encode($raw));

        $this->assertSame(['q1' => 3, 'q2' => 0], $assessment->answers);
        $this->assertTrue($assessment->verifyAnswer('q1', 3));
        $this->assertFalse($assessment->verifyAnswer('q1', 2));
        $this->assertFalse($assessment->verifyAnswer('q9', 0));
        $this->assertTrue($assessment->verifyAllAnswers(['q1' => 3, 'q2' => 0]));
        $this->assertFalse($assessment->verifyAllAnswers(['q1' => 3, 'q2' => 1]));
    }

    public function test_legacy_rows_with_digests_still_decrypt(): void
    {
        $assessment = new Assessment;
        $assessment->setRawAttributes(['answers' => json_encode([
            'q1' => ['encrypted' => encrypt('2', false), 'hash' => hash('sha256', '2')],
        ])]);

        $this->assertSame(2, $assessment->getAnswer('q1'));
        $this->assertTrue($assessment->verifyAnswer('q1', 2));
    }
}
