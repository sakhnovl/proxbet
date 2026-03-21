<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../public_support.php';
require_once __DIR__ . '/../public_messages.php';

final class PublicMessagesTest extends TestCase
{
    public function testBuildBalanceMessageUsesHighlightedWalletLayout(): void
    {
        $message = buildBalanceMessage(['ai_balance' => 999999987]);

        $this->assertStringContainsString('💼 <b>Ваш AI-кошелёк</b>', $message);
        $this->assertStringContainsString('├ Статус: 🟢 <b>готов к анализам</b>', $message);
        $this->assertStringContainsString('├ Баланс: <b>999999987</b> кредитов', $message);
        $this->assertStringContainsString('└ Стоимость 1 AI-анализа: <b>1</b> кредит', $message);
    }

    public function testBuildAnalysisDeliveryMessageIncludesStyledHeaderAndWalletBlock(): void
    {
        $message = buildAnalysisDeliveryMessage('Тестовый разбор.', ['ai_balance' => 5]);

        $this->assertStringContainsString('🤖 <b>AI-анализ матча</b>', $message);
        $this->assertStringContainsString('Тестовый разбор.', $message);
        $this->assertStringContainsString('💼 <b>Ваш AI-кошелёк</b>', $message);
    }

    public function testBuildBalanceMessageUsesLowBalanceStatusAndPluralForms(): void
    {
        putenv('AI_ANALYSIS_COST=2');

        try {
            $message = buildBalanceMessage(['ai_balance' => 3]);
        } finally {
            putenv('AI_ANALYSIS_COST');
        }

        $this->assertStringContainsString('├ Статус: 🟡 <b>кредитов немного</b>', $message);
        $this->assertStringContainsString('├ Баланс: <b>3</b> кредита', $message);
        $this->assertStringContainsString('└ Стоимость 1 AI-анализа: <b>2</b> кредита', $message);
    }

    public function testBuildBalanceMessageUsesTopUpStatusWhenBalanceIsInsufficient(): void
    {
        putenv('AI_ANALYSIS_COST=5');

        try {
            $message = buildBalanceMessage(['ai_balance' => 1]);
        } finally {
            putenv('AI_ANALYSIS_COST');
        }

        $this->assertStringContainsString('├ Статус: 🔴 <b>нужно пополнение</b>', $message);
        $this->assertStringContainsString('├ Баланс: <b>1</b> кредит', $message);
        $this->assertStringContainsString('└ Стоимость 1 AI-анализа: <b>5</b> кредитов', $message);
    }
}
