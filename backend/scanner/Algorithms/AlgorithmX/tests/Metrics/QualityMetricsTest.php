<?php

declare(strict_types=1);

namespace Proxbet\Scanner\Algorithms\AlgorithmX\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Proxbet\Scanner\Algorithms\AlgorithmX\Metrics\QualityMetrics;

/**
 * Tests for QualityMetrics calculator.
 */
final class QualityMetricsTest extends TestCase
{
    private QualityMetrics $metrics;
    
    protected function setUp(): void
    {
        $this->metrics = new QualityMetrics();
    }
    
    public function testCalculateBrierScorePerfectPredictions(): void
    {
        $predictions = [
            ['predicted' => 1.0, 'actual' => true],
            ['predicted' => 0.0, 'actual' => false],
            ['predicted' => 1.0, 'actual' => true],
            ['predicted' => 0.0, 'actual' => false],
        ];
        
        $score = $this->metrics->calculateBrierScore($predictions);
        
        $this->assertEqualsWithDelta(0.0, $score, 0.001);
    }
    
    public function testCalculateBrierScoreWorstPredictions(): void
    {
        $predictions = [
            ['predicted' => 0.0, 'actual' => true],
            ['predicted' => 1.0, 'actual' => false],
            ['predicted' => 0.0, 'actual' => true],
            ['predicted' => 1.0, 'actual' => false],
        ];
        
        $score = $this->metrics->calculateBrierScore($predictions);
        
        $this->assertEqualsWithDelta(1.0, $score, 0.001);
    }
    
    public function testCalculateBrierScoreMixedPredictions(): void
    {
        $predictions = [
            ['predicted' => 0.7, 'actual' => true],   // (0.7-1)^2 = 0.09
            ['predicted' => 0.3, 'actual' => false],  // (0.3-0)^2 = 0.09
            ['predicted' => 0.6, 'actual' => true],   // (0.6-1)^2 = 0.16
            ['predicted' => 0.4, 'actual' => false],  // (0.4-0)^2 = 0.16
        ];
        
        $score = $this->metrics->calculateBrierScore($predictions);
        
        // Average: (0.09 + 0.09 + 0.16 + 0.16) / 4 = 0.125
        $this->assertEqualsWithDelta(0.125, $score, 0.001);
    }
    
    public function testCalculateBrierScoreEmptyArray(): void
    {
        $score = $this->metrics->calculateBrierScore([]);
        
        $this->assertEquals(1.0, $score);
    }
    
    public function testCalculateRocAucPerfectDiscrimination(): void
    {
        $predictions = [
            ['predicted' => 0.9, 'actual' => true],
            ['predicted' => 0.8, 'actual' => true],
            ['predicted' => 0.3, 'actual' => false],
            ['predicted' => 0.2, 'actual' => false],
        ];
        
        $auc = $this->metrics->calculateRocAuc($predictions);
        
        $this->assertEqualsWithDelta(1.0, $auc, 0.001);
    }
    
    public function testCalculateRocAucRandomDiscrimination(): void
    {
        $predictions = [
            ['predicted' => 0.5, 'actual' => true],
            ['predicted' => 0.5, 'actual' => true],
            ['predicted' => 0.5, 'actual' => false],
            ['predicted' => 0.5, 'actual' => false],
        ];
        
        $auc = $this->metrics->calculateRocAuc($predictions);
        
        $this->assertEqualsWithDelta(0.5, $auc, 0.001);
    }
    
    public function testCalculateRocAucMixedDiscrimination(): void
    {
        $predictions = [
            ['predicted' => 0.8, 'actual' => true],
            ['predicted' => 0.6, 'actual' => true],
            ['predicted' => 0.4, 'actual' => false],
            ['predicted' => 0.7, 'actual' => false],
        ];
        
        $auc = $this->metrics->calculateRocAuc($predictions);
        
        // Positive scores: [0.8, 0.6]
        // Negative scores: [0.4, 0.7]
        // Pairs: (0.8, 0.4)=1, (0.8, 0.7)=1, (0.6, 0.4)=1, (0.6, 0.7)=0
        // AUC = 3/4 = 0.75
        $this->assertEqualsWithDelta(0.75, $auc, 0.001);
    }
    
    public function testCalculateRocAucEmptyArray(): void
    {
        $auc = $this->metrics->calculateRocAuc([]);
        
        $this->assertEquals(0.5, $auc);
    }
    
    public function testCalculateRocAucOnlyPositives(): void
    {
        $predictions = [
            ['predicted' => 0.8, 'actual' => true],
            ['predicted' => 0.6, 'actual' => true],
        ];
        
        $auc = $this->metrics->calculateRocAuc($predictions);
        
        $this->assertEquals(0.5, $auc);
    }
    
    public function testCalculateRocAucOnlyNegatives(): void
    {
        $predictions = [
            ['predicted' => 0.4, 'actual' => false],
            ['predicted' => 0.2, 'actual' => false],
        ];
        
        $auc = $this->metrics->calculateRocAuc($predictions);
        
        $this->assertEquals(0.5, $auc);
    }
    
    public function testCalculateCalibrationCurve(): void
    {
        $predictions = [
            ['predicted' => 0.15, 'actual' => false],
            ['predicted' => 0.18, 'actual' => true],
            ['predicted' => 0.55, 'actual' => true],
            ['predicted' => 0.52, 'actual' => false],
            ['predicted' => 0.85, 'actual' => true],
            ['predicted' => 0.88, 'actual' => true],
        ];
        
        $curve = $this->metrics->calculateCalibrationCurve($predictions, 10);
        
        $this->assertIsArray($curve);
        $this->assertGreaterThan(0, count($curve));
        
        foreach ($curve as $bin) {
            $this->assertArrayHasKey('bin', $bin);
            $this->assertArrayHasKey('predicted_mean', $bin);
            $this->assertArrayHasKey('actual_frequency', $bin);
            $this->assertArrayHasKey('count', $bin);
        }
    }
    
    public function testCalculateCalibrationCurveEmptyArray(): void
    {
        $curve = $this->metrics->calculateCalibrationCurve([]);
        
        $this->assertIsArray($curve);
        $this->assertEmpty($curve);
    }
    
    public function testCalculateMetrics(): void
    {
        $predictions = [
            ['predicted' => 0.7, 'actual' => true],
            ['predicted' => 0.3, 'actual' => false],
            ['predicted' => 0.8, 'actual' => true],
            ['predicted' => 0.2, 'actual' => false],
        ];
        
        $metrics = $this->metrics->calculateMetrics($predictions);
        
        $this->assertArrayHasKey('brier_score', $metrics);
        $this->assertArrayHasKey('roc_auc', $metrics);
        $this->assertArrayHasKey('total_predictions', $metrics);
        $this->assertArrayHasKey('positive_cases', $metrics);
        $this->assertArrayHasKey('negative_cases', $metrics);
        
        $this->assertEquals(4, $metrics['total_predictions']);
        $this->assertEquals(2, $metrics['positive_cases']);
        $this->assertEquals(2, $metrics['negative_cases']);
        
        $this->assertGreaterThan(0, $metrics['brier_score']);
        $this->assertLessThan(1, $metrics['brier_score']);
        $this->assertGreaterThan(0, $metrics['roc_auc']);
        $this->assertLessThanOrEqual(1, $metrics['roc_auc']);
    }
}
