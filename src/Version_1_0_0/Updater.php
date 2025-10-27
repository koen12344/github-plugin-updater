<?php

namespace Koen12344\GithubPluginUpdater\Version_1_0_0;

use WP_Error;

//Make sure only one instance of this version can be declared if multiple plugins implement the same version of this package
if (!class_exists( __NAMESPACE__ . '\\Updater')) {
    return;
}

class Updater
{
	/**
	 * @var \WP_Http
	 */
	private $transport;
	private $plugin_file;
    private $github_user;
    private $github_repo;
    private $current_version;
    /**
     * @var null
     */
    private $access_token;

	public function __construct(\WP_Http $transport, string $plugin_file, string $github_user, string $github_repo, string $current_version, string $access_token = null){
		$this->transport            = $transport;
        $this->plugin_file          = $plugin_file;
        $this->github_user          = $github_user;
        $this->github_repo          = $github_repo;
        $this->current_version      = $current_version;
        $this->access_token         = $access_token;
	}

    public function register(){
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'maybe_rename_source'], 10, 3);
    }


	public function check_for_update($transient) {
		if (empty($transient->checked)) {
			return $transient;
		}

		$remote_info = $this->get_github_release_info();

		if (
			$remote_info &&
			version_compare($this->current_version, ltrim($remote_info->tag_name, 'v'), '<')
		) {
			$plugin_slug = plugin_basename($this->plugin_file);

			$transient->response[$plugin_slug] = (object) [
				'slug'              => $plugin_slug,
				'new_version'       => $remote_info->tag_name,
				'package'           => $remote_info->zipball_url,
				'url'               => $remote_info->html_url,
			];
		}

		return $transient;
	}

	public function plugin_info($result, $action, $args) {
		if ($action !== 'plugin_information') {
			return $result;
		}

		if ($args->slug !== plugin_basename($this->plugin_file)) {
			return $result;
		}

		$remote_info = $this->get_github_release_info();

		if (!$remote_info) {
			return $result;
		}

		return (object) [
			'name' => $this->github_repo,
			'slug' => plugin_basename($this->plugin_file),
			'version' => $remote_info->tag_name,
			'author' => $remote_info->author->login,
			'homepage' => $remote_info->html_url,
			'download_link' => $remote_info->zipball_url,
			'sections' => [
				'description' => $remote_info->body,
			],
		];
	}

	public function maybe_rename_source($source, $remote_source, $plugin) {
		if (strpos($source, $this->github_repo) !== false) {
			$new_source = trailingslashit(dirname($source)) . plugin_basename($this->plugin_file);
			rename($source, $new_source);
			return $new_source;
		}

		return $source;
	}

	private function get_github_release_info() {
		$url = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";

		$args = [
			'headers' => [
				'Accept' => 'application/vnd.github.v3+json',
			],
		];

		if ($this->access_token) {
			$args['headers']['Authorization'] = 'token ' . $this->access_token;
		}

		$response = $this->transport->request($url, $args);

		if (
			$response instanceof WP_Error ||
			$response['response']['code'] !== 200
		) {
			return false;
		}

		return json_decode($response['body']);
	}
}
