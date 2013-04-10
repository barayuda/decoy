<?php namespace Bkwld\Decoy;

use \Config;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\ProviderRepository;
use Illuminate\Support\ServiceProvider;
use \Former;

class DecoyServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		$this->package('bkwld/decoy');
		
		// Load the other packages that we depend on.  Doing it here so the developer
		// doesn't need to add them to the app config
		// THIS DIDN'T WORK, SEE https://github.com/BKWLD/decoy/issues/67
		// $services = new ProviderRepository(new Filesystem, Config::get('app.manifest'));
		// $services->load($this->app, array(
		// 	'Former\FormerServiceProvider',
		// ));

		// Define constants that Decoy uses
		if (!defined('UPLOAD_DELETE'))   define('UPLOAD_DELETE', 'delete-');
		if (!defined('UPLOAD_OLD'))      define('UPLOAD_OLD', 'old-');
		if (!defined('UPLOAD_REPLACE'))  define('UPLOAD_REPLACE', 'replace-');
		if (!defined('FORMAT_DATE'))     define('FORMAT_DATE', 'm/d/y');
		if (!defined('FORMAT_DATETIME')) define('FORMAT_DATETIME', 'm/d/y g:i a T');
		if (!defined('FORMAT_TIME'))     define('FORMAT_TIME', 'g:i a T');
		
		// Alias the auth class that is defined in the config for easier referencing.
		// Call it "DecoyAuth"
		if (!class_exists('DecoyAuth')) {
			$auth_class = Config::get('decoy::auth_class');
			if (!class_exists($auth_class)) throw new Exception('Auth class does not exist: '.$auth_class);
			class_alias($auth_class, 'DecoyAuth', true);
			if (!is_a(new \DecoyAuth, 'Bkwld\Decoy\Auth\AuthInterface')) throw new Exception('Auth class does not implement Auth\AuthInterface:'.$auth_class);
		}
		
		// Load HTML helpers
		require_once(__DIR__.'/../../helpers.php');
		
		// Register the routes
		require_once(__DIR__.'/../../routes.php');
		
		// Load all the composers
		require_once(__DIR__.'/../../composers/layouts._breadcrumbs.php');
		require_once(__DIR__.'/../../composers/layouts._nav.php');
		require_once(__DIR__.'/../../composers/shared.list._standard.php');
		
		// Change former's required field HTML
		Config::set('former::required_text', ' <i class="icon-exclamation-sign js-tooltip required" title="Required field"></i>');

		// Tell former to include unchecked checkboxes in the post
		Config::set('former::push_checkboxes', true);
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		//
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array();
	}

}