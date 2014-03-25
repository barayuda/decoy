<?php namespace Bkwld\Decoy\Routing;

// Dependencies
use Bkwld\Decoy\Controllers\Base;
use Bkwld\Decoy\Exception;
use Bkwld\Decoy\Routing\Wildcard;
use Bkwld\Decoy\Routing\UrlGenerator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Request;

/**
 * This class tries to figure out if the injected controller has parents
 * and who they are.  There is an assumption with this logic that ancestry
 * only matters to controllers that were resolved through Wildcard
 */
class Ancestry {
	
	/**
	 * Inject dependencies
	 * @param Bkwld\Decoy\Controllers\Base $controller
	 * @param Bkwld\Decoy\Routing\Wildcard $wildcard
	 * @param Symfony\Component\HttpFoundation\Request $input
	 * @param Bkwld\Decoy\Routing\UrlGenerator $url_generator
	 */
	private $controller;
	private $router;
	private $wildcard;
	private $url_generator;
	public function __construct(Base $controller, Wildcard $wildcard, Request $input, UrlGenerator $url_generator) {
		$this->controller = $controller;
		$this->wildcard = $wildcard;
		$this->input = $input;
		$this->url_generator = $url_generator;
	}
	
	/**
	 * Test if the current route is serviced by has many and/or belongs to.  These
	 * are only true if this controller is acting in a child role
	 * 
	 */
	public function isChildRoute() {
		return $this->requestIsChild()
			|| $this->parentIsInInput()
			|| $this->isActingAsRelated();
	}
	
	/**
	 * Test if the current URL is for a controller acting in a child capacity.  We're only
	 * checking wilcarded routes (not any that were explictly registered), because I think
	 * it unlikely that we'd ever explicitly register routes to be children of another.
	 */
	public function requestIsChild() {

		// Get all the classes represented in the request
		$classes = $this->wildcard->getAllClasses();
		
		// Remove the first class, because we only care about children
		if (count($classes) <= 1) return false;
		array_shift($classes);

		// Check if this controller is in the the remaining list of classes
		return in_array(get_class($this->controller), $classes);
	}

	/**
	 * Test if the current route is one of the many to many XHR requests
	 */
	public function parentIsInInput() {

		// This is check is only allowed if the request is for this controller.  If other
		// controller instances are instantiated, they were not designed to be informed by the input.
		if (!$this->isRouteController()) return false;
		
		// Check for a property in the AJAX input of 'parent_controller'
		return $this->input->has('parent_controller');
	}
	
	/**
	 * Test if the controller may be being used in rendering a related list within another.  In other
	 * words, the controller is different than the request and you're on an edit page.
	 */
	public function isActingAsRelated() {
		
		// We're also testing that this controller isn't in the URI.  This would never be the case when 
		// something was in the sidebar.  But without it, deducing the breadcrumbs gets confused because 
		// controllers get instantiated not on their route but aren't the children of the current route.
		// So I convert the controller to it's URL representation and then make sure it is not present
		// in the current URL.
		$test = $this->url_generator->action($this->controller->controller()); // ex: /admin/articles
		if (strpos('/'.$this->input->path(), $test) !== false) return false;
		
		// Check that we're on an edit page
		return $this->wildcard->detectAction() === 'edit';

	}
	
	/**
	 * Test if the request is for a controller
	 */
	public function isRouteController() {
		return $this->wildcard->detectController() === get_class($this->controller);
	}
	
	/**
	 * Return a boolean for whether the parent relationship represents a many to many.  This is
	 * different from isChildRoute() because it also checks what kind of relationship the child
	 * is in.
	 */
	public function isChildInManyToMany() {

		// See if a relationship is defined
		$relationship = $this->controller->selfToParent();
		if (!$relationship) return false;

		// If the relationship ends in 'able' then it's assumed to be
		// a polymorphic one-to-many.  We're doing it this way because 
		// running the relationship function (see `$model->{$relationship}()` below)
		// throws an error when you're not working with a hydrated model.  And this
		// is exactly what happens in the the shared.list._standard view composer.
		if (Str::endsWith($relationship, 'able')) return false;

		// Check the class of the relationship
		$model = $this->controller->model();
		if (!method_exists($model, $relationship)) return false;
		$model = new $model; // Needed to be a simple string to work
		return is_a($model->{$relationship}(), 'Illuminate\Database\Eloquent\Relations\BelongsToMany');
	}
	
	/**
	 * Guess at what the parent controller is by examing the route or input varibles
	 * @return string ex: Admin\NewsController
	 */
	public function deduceParentController() {
		
		// If one of the many to many xhr requests, get the parent from Input
		if ($this->parentIsInInput()) {
			
			 // Can't use ->get() because it has a different meaning in Request
			return $this->input->input('parent_controller');
		
		// If a child index view, get the controller from the route
		} else if ($this->requestIsChild()) {
			return $this->getParentController();
		
		// If this controller is a related view of another, the parent is the main request	
		} else if ($this->isActingAsRelated()) {
			return $this->wildcard->detectController();
		
		// No parent found
		} else return false;
	}
	
	/**
	 * Use the array of classes represented in the current route to find the one that
	 * preceeds the current controller.  The preceeding controller is considered to be
	 * the parent.
	 * @return string ex: Admin\NewsController
	 */
	public function getParentController() {
		$classes = $this->wildcard->getAllClasses();
		$i = array_search(get_class($this->controller), $this->wildcard->getAllClasses());
		if ($i > 0) return $classes[$i-1];
		else throw new Exception('Error getting the parent controller.');
	}

	/**
	 * Guess as what the relationship function on the parent model will be
	 * that points back to the model for this controller by using THIS
	 * controller's name.
	 * @return string ex: "slides" if this the slides controller
	 */
	public function deduceParentRelationship() {
		
		// The relationship is generally a plural form of the model name.
		// For instance, if Article has-many SuperSlide, then there will be a "superSlides"
		// relationship on Article.
		$model = $this->getClassName($this->controller->model());
		$relationship = Str::plural(lcfirst($model));
		
		// Verify that it exists
		if (!method_exists($this->controller->parentModel(), $relationship)) {
			throw new Exception('Parent relationship missing, looking for: '.$relationship
				.'. This controller is: '.get_class($this->controller)
				.'. This parent model is: '.$this->controller->parentModel()
			);
		}
		return $relationship;
	}
	
	/**
	 * Guess at what the child relationship name is, which is on the active controller,
	 * pointing back to the parent.  This is typically the same
	 * as the parent model.  For instance, Post has many Image.  Image will have
	 * a function named "post" for it's relationship
	 */
	public function deduceChildRelationship() {
		$model = $this->controller->model();

		// If one to many, it will be singular
		$parent_model = $this->getClassName($this->controller->parentModel());
		$relationship = lcfirst($parent_model);
		if (method_exists($model, $relationship)) return $relationship;
		
		// If it doesn't exist, try the plural version, which would be correct
		// for a many to many relationship
		$plural = Str::plural($relationship);
		if (method_exists($model, $plural)) return $plural;

		// Look for a polymorphic version, which we get by appending 'able' to the
		// end of the singular version.  Ex: "Image" model would have imageable().
		$poly = strtolower($model).'able';
		if (method_exists($model, $poly)) return $poly;

		// Throw exception
		throw new Exception('Child relationship missing, looking for '.$relationship);
	}
	
	/**
	 * Get the parent controller's id from the route or return false
	 * @return mixed An id or false
	 */
	public function parentId() {
		if (!$this->requestIsChild()) return false;
		return $this->wildcard->detectParentId();
	}
	
	/**
	 * Take a model fullly namespaced class name and get just the class
	 * @param string $class ex: Bkwld\Decoy\Models\Admin
	 * @return string ex: Admin
	 */
	private function getClassName($class) {
		if (preg_match('#[a-z-]+$#i', $class, $matches)) return $matches[0];
		throw new Exception('Class name could not be found: '. $class);
	}
	
}