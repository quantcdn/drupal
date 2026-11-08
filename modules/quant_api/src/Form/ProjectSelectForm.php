<?php

namespace Drupal\quant_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\OAuth\AuthService;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_api\OAuth\Platform;
use Drupal\quant_api\OAuth\PortalClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The second step of "Log in to Quant": choose the project of this site.
 *
 * On submit the module limits the login to the chosen project, reads that
 * project's write token and makes it the site's connection. Until then the
 * login waits in the expirable pending store and publishes nothing.
 */
class ProjectSelectForm extends FormBase {

  public function __construct(
    protected AuthService $auth,
    protected PortalClient $portal,
    protected OAuthTokenStore $tokenStore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quant_api.oauth'),
      $container->get('quant_api.portal'),
      $container->get('quant_api.oauth_store'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_api_oauth_project';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $login = $this->tokenStore->getPendingLogin((int) $this->currentUser()->id());
    if ($login === NULL) {
      return $this->expired($form);
    }
    $this->auth->usePlatform($login['platform'] ?? NULL);
    $options = $this->projectOptions($login);
    if ($options === []) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('Your Quant account has no projects in the organisations you approved. Create a project in the Quant dashboard, then log in again.') . '</p>',
      ];
      return $form + $this->backLink();
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Logged in as %user. Choose the Quant project that this site publishes to. This site can then reach only that project.', [
        '%user' => $login['user']['email'] ?: $login['user']['name'],
      ]) . '</p>',
    ];
    $choices = array_merge(...array_values(array_map('array_keys', $options)));
    $form['project'] = [
      '#type' => 'select',
      '#title' => $this->t('Project'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => count($choices) === 1 ? $choices[0] : NULL,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Use this project'),
      '#button_type' => 'primary',
    ];
    return $form + $this->backLink();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $login = $this->tokenStore->getPendingLogin((int) $this->currentUser()->id());
    [$org, $project] = array_pad(explode(':', (string) $form_state->getValue('project'), 2), 2, '');
    if ($login === NULL || !isset($login['organizations'][$org]) || !PortalClient::isMachineName($project)) {
      $form_state->setErrorByName('project', $this->t('The login expired. Log in to Quant again.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uid = (int) $this->currentUser()->id();
    $login = $this->tokenStore->getPendingLogin($uid);
    $this->auth->usePlatform($login['platform'] ?? NULL);
    $platform = $this->auth->getPlatform();
    [$org, $project] = explode(':', (string) $form_state->getValue('project'), 2);
    $access = (string) $login['tokens']['access_token'];

    $write_token = $this->portal->limitToProject($access, $org, $project)
      ? $this->portal->fetchWriteToken($access, $org, $project)
      : NULL;
    // A limited login cannot choose another project, so it is never reused.
    $this->tokenStore->deletePendingLogin($uid);
    if ($write_token === NULL) {
      $this->messenger()->addError($this->t('Quant did not give this site access to the project. Log in again, or connect with a project token.'));
      $form_state->setRedirect('quant_api.settings_form');
      return;
    }

    $this->auth->saveConnection($login['tokens'], $write_token, $login['user']);
    $this->configFactory()->getEditable('quant_api.settings')
      ->set('auth_method', Credentials::METHOD_OAUTH)
      ->set('platform', $platform)
      ->set('api_endpoint', Platform::apiEndpoint($platform))
      ->set('api_account', $org)
      ->set('api_project', $project)
      ->set('api_token', NULL)
      ->save();
    \Drupal::service('router.builder')->setRebuildNeeded();

    $this->messenger()->addStatus($this->t('This site now publishes to the Quant project %project.', ['%project' => $project]));
    $form_state->setRedirect('quant_api.settings_form');
  }

  /**
   * The projects of each approved organisation, grouped by organisation.
   *
   * @return array
   *   Organisation name => ["org:project" => project name].
   */
  protected function projectOptions(array $login): array {
    $options = [];
    foreach ($login['organizations'] as $org => $org_name) {
      foreach ($this->portal->listProjects((string) $login['tokens']['access_token'], (string) $org) ?? [] as $project) {
        $options[$org_name][$org . ':' . $project['machine_name']] = $project['name'];
      }
    }
    return $options;
  }

  /**
   * The form when no login waits for a project choice.
   */
  protected function expired(array $form): array {
    $form['expired'] = [
      '#markup' => '<p>' . $this->t('No login waits for a project choice. A login waits @minutes minutes. Log in to Quant again.', [
        '@minutes' => OAuthTokenStore::PENDING_TTL / 60,
      ]) . '</p>',
    ];
    return $form + $this->backLink();
  }

  /**
   * A link back to the settings page.
   */
  protected function backLink(): array {
    return [
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('Back to the Quant API settings'),
        '#url' => Url::fromRoute('quant_api.settings_form'),
      ],
    ];
  }

}
