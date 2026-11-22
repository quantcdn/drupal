<?php

namespace Drupal\Tests\quant_webform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_webform\QuantFormsSettingsSection;
use Drupal\webform\Entity\Webform;
use Drupal\webform\WebformInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Which posted channels a save keeps, without the form system in front.
 *
 * The form system drops posted fields that the rebuilt form does not have.
 * These tests call the selection rules directly, so each rule is the only
 * protection.
 *
 * @coversDefaultClass \Drupal\quant_webform\QuantFormsSettingsSection
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class ChannelSelectionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'file', 'node', 'taxonomy', 'views',
    'webform', 'quant', 'quant_api', 'quant_webform',
  ];

  /**
   * The list of the organisation, as build() keeps it on the element.
   */
  protected const LIST = [
    'state' => 'ok',
    'channels' => [
      ['id' => 'ch_email1', 'type' => 'email'],
      ['id' => 'ch_slack1', 'type' => 'slack'],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform', 'quant_webform']);
  }

  /**
   * A Webform with stored channels.
   */
  protected function webform(array $channels = []): WebformInterface {
    $webform = Webform::create([
      'id' => 'channel_test',
      'title' => 'Channel test',
      'elements' => "name: { '#type': textfield, '#title': Name }",
    ]);
    $webform->setThirdPartySetting('quant_webform', 'channels', $channels)->save();
    return $webform;
  }

  /**
   * The section.
   */
  protected function section(): QuantFormsSettingsSection {
    return $this->container->get('quant_webform.settings_section');
  }

  /**
   * An ID that is neither listed nor stored is not kept.
   *
   * @covers ::flattenChannels
   */
  public function testUnlistedUnstoredIdIsDropped(): void {
    $posted = [
      'ch_forged' => ['use' => 1, 'cc' => 1],
      'ch_email1' => ['use' => 1, 'cc' => 0],
    ];
    $this->assertSame([['id' => 'ch_email1', 'cc' => FALSE]], $this->section()->flattenChannels($posted, $this->webform(), self::LIST));
  }

  /**
   * Cc only for a listed email channel, or a stored cc that is not listed.
   *
   * @covers ::flattenChannels
   */
  public function testCcRule(): void {
    $webform = $this->webform([
      ['id' => 'ch_slack1', 'cc' => TRUE],
      ['id' => 'ch_gone', 'cc' => TRUE],
      ['id' => 'ch_gone2', 'cc' => FALSE],
    ]);
    $posted = [
      'ch_email1' => ['use' => 1, 'cc' => 1],
      'ch_slack1' => ['use' => 1, 'cc' => 1],
      'ch_gone' => ['use' => 1, 'cc' => 1],
      'ch_gone2' => ['use' => 1, 'cc' => 1],
    ];
    $this->assertSame([
      ['id' => 'ch_email1', 'cc' => TRUE],
      ['id' => 'ch_slack1', 'cc' => FALSE],
      ['id' => 'ch_gone', 'cc' => TRUE],
      ['id' => 'ch_gone2', 'cc' => FALSE],
    ], $this->section()->flattenChannels($posted, $webform, self::LIST));
  }

  /**
   * Without the list, the stored channels stay; the post changes nothing.
   *
   * @covers ::flattenChannels
   */
  public function testNoListKeepsStored(): void {
    $webform = $this->webform([['id' => 'ch_email1', 'cc' => TRUE]]);
    $posted = ['ch_email1' => ['use' => 0], 'ch_forged' => ['use' => 1]];
    $list = ['state' => 'unavailable', 'channels' => []];
    $this->assertSame([['id' => 'ch_email1', 'cc' => TRUE]], $this->section()->flattenChannels($posted, $webform, $list));
  }

}
