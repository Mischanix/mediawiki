<?php
/**
 * Helper class for the index.php entry point.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

use MediaWiki\Logger\LoggerFactory;

/**
 * The MediaWiki class is the helper class for the index.php entry point.
 */
class MediaWiki {
	/**
	 * @var IContextSource
	 */
	private $context;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @param IContextSource|null $context
	 */
	public function __construct( IContextSource $context = null ) {
		if ( !$context ) {
			$context = RequestContext::getMain();
		}

		$this->context = $context;
		$this->config = $context->getConfig();
	}

	/**
	 * Parse the request to get the Title object
	 *
	 * @throws MalformedTitleException If a title has been provided by the user, but is invalid.
	 * @return Title Title object to be $wgTitle
	 */
	private function parseTitle() {
		global $wgContLang;

		$request = $this->context->getRequest();
		$curid = $request->getInt( 'curid' );
		$title = $request->getVal( 'title' );
		$action = $request->getVal( 'action' );

		if ( $request->getCheck( 'search' ) ) {
			// Compatibility with old search URLs which didn't use Special:Search
			// Just check for presence here, so blank requests still
			// show the search page when using ugly URLs (bug 8054).
			$ret = SpecialPage::getTitleFor( 'Search' );
		} elseif ( $curid ) {
			// URLs like this are generated by RC, because rc_title isn't always accurate
			$ret = Title::newFromID( $curid );
		} else {
			$ret = Title::newFromURL( $title );
			// Alias NS_MEDIA page URLs to NS_FILE...we only use NS_MEDIA
			// in wikitext links to tell Parser to make a direct file link
			if ( !is_null( $ret ) && $ret->getNamespace() == NS_MEDIA ) {
				$ret = Title::makeTitle( NS_FILE, $ret->getDBkey() );
			}
			// Check variant links so that interwiki links don't have to worry
			// about the possible different language variants
			if ( count( $wgContLang->getVariants() ) > 1
				&& !is_null( $ret ) && $ret->getArticleID() == 0
			) {
				$wgContLang->findVariantLink( $title, $ret );
			}
		}

		// If title is not provided, always allow oldid and diff to set the title.
		// If title is provided, allow oldid and diff to override the title, unless
		// we are talking about a special page which might use these parameters for
		// other purposes.
		if ( $ret === null || !$ret->isSpecialPage() ) {
			// We can have urls with just ?diff=,?oldid= or even just ?diff=
			$oldid = $request->getInt( 'oldid' );
			$oldid = $oldid ? $oldid : $request->getInt( 'diff' );
			// Allow oldid to override a changed or missing title
			if ( $oldid ) {
				$rev = Revision::newFromId( $oldid );
				$ret = $rev ? $rev->getTitle() : $ret;
			}
		}

		// Use the main page as default title if nothing else has been provided
		if ( $ret === null
			&& strval( $title ) === ''
			&& !$request->getCheck( 'curid' )
			&& $action !== 'delete'
		) {
			$ret = Title::newMainPage();
		}

		if ( $ret === null || ( $ret->getDBkey() == '' && !$ret->isExternal() ) ) {
			// If we get here, we definitely don't have a valid title; throw an exception.
			// Try to get detailed invalid title exception first, fall back to MalformedTitleException.
			Title::newFromTextThrow( $title );
			throw new MalformedTitleException( 'badtitletext', $title );
		}

		return $ret;
	}

	/**
	 * Get the Title object that we'll be acting on, as specified in the WebRequest
	 * @return Title
	 */
	public function getTitle() {
		if ( !$this->context->hasTitle() ) {
			try {
				$this->context->setTitle( $this->parseTitle() );
			} catch ( MalformedTitleException $ex ) {
				$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
			}
		}
		return $this->context->getTitle();
	}

	/**
	 * Returns the name of the action that will be executed.
	 *
	 * @return string Action
	 */
	public function getAction() {
		static $action = null;

		if ( $action === null ) {
			$action = Action::getActionName( $this->context );
		}

		return $action;
	}

	/**
	 * Performs the request.
	 * - bad titles
	 * - read restriction
	 * - local interwiki redirects
	 * - redirect loop
	 * - special pages
	 * - normal pages
	 *
	 * @throws MWException|PermissionsError|BadTitleError|HttpError
	 * @return void
	 */
	private function performRequest() {
		global $wgTitle;

		$request = $this->context->getRequest();
		$requestTitle = $title = $this->context->getTitle();
		$output = $this->context->getOutput();
		$user = $this->context->getUser();

		if ( $request->getVal( 'printable' ) === 'yes' ) {
			$output->setPrintable();
		}

		$unused = null; // To pass it by reference
		Hooks::run( 'BeforeInitialize', array( &$title, &$unused, &$output, &$user, $request, $this ) );

		// Invalid titles. Bug 21776: The interwikis must redirect even if the page name is empty.
		if ( is_null( $title ) || ( $title->getDBkey() == '' && !$title->isExternal() )
			|| $title->isSpecial( 'Badtitle' )
		) {
			$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
			try {
				$this->parseTitle();
			} catch ( MalformedTitleException $ex ) {
				throw new BadTitleError( $ex );
			}
			throw new BadTitleError();
		}

		// Check user's permissions to read this page.
		// We have to check here to catch special pages etc.
		// We will check again in Article::view().
		$permErrors = $title->isSpecial( 'RunJobs' )
			? array() // relies on HMAC key signature alone
			: $title->getUserPermissionsErrors( 'read', $user );
		if ( count( $permErrors ) ) {
			// Bug 32276: allowing the skin to generate output with $wgTitle or
			// $this->context->title set to the input title would allow anonymous users to
			// determine whether a page exists, potentially leaking private data. In fact, the
			// curid and oldid request  parameters would allow page titles to be enumerated even
			// when they are not guessable. So we reset the title to Special:Badtitle before the
			// permissions error is displayed.

			// The skin mostly uses $this->context->getTitle() these days, but some extensions
			// still use $wgTitle.
			$badTitle = SpecialPage::getTitleFor( 'Badtitle' );
			$this->context->setTitle( $badTitle );
			$wgTitle = $badTitle;

			throw new PermissionsError( 'read', $permErrors );
		}

		// Interwiki redirects
		if ( $title->isExternal() ) {
			$rdfrom = $request->getVal( 'rdfrom' );
			if ( $rdfrom ) {
				$url = $title->getFullURL( array( 'rdfrom' => $rdfrom ) );
			} else {
				$query = $request->getValues();
				unset( $query['title'] );
				$url = $title->getFullURL( $query );
			}
			// Check for a redirect loop
			if ( !preg_match( '/^' . preg_quote( $this->config->get( 'Server' ), '/' ) . '/', $url )
				&& $title->isLocal()
			) {
				// 301 so google et al report the target as the actual url.
				$output->redirect( $url, 301 );
			} else {
				$this->context->setTitle( SpecialPage::getTitleFor( 'Badtitle' ) );
				try {
					$this->parseTitle();
				} catch ( MalformedTitleException $ex ) {
					throw new BadTitleError( $ex );
				}
				throw new BadTitleError();
			}
		// Handle any other redirects.
		// Redirect loops, titleless URL, $wgUsePathInfo URLs, and URLs with a variant
		} elseif ( !$this->tryNormaliseRedirect( $title ) ) {

			// Special pages
			if ( NS_SPECIAL == $title->getNamespace() ) {
				// Actions that need to be made when we have a special pages
				SpecialPageFactory::executePath( $title, $this->context );
			} else {
				// ...otherwise treat it as an article view. The article
				// may still be a wikipage redirect to another article or URL.
				$article = $this->initializeArticle();
				if ( is_object( $article ) ) {
					$this->performAction( $article, $requestTitle );
				} elseif ( is_string( $article ) ) {
					$output->redirect( $article );
				} else {
					throw new MWException( "Shouldn't happen: MediaWiki::initializeArticle()"
						. " returned neither an object nor a URL" );
				}
			}
		}
	}

	/**
	 * Handle redirects for uncanonical title requests.
	 *
	 * Handles:
	 * - Redirect loops.
	 * - No title in URL.
	 * - $wgUsePathInfo URLs.
	 * - URLs with a variant.
	 * - Other non-standard URLs (as long as they have no extra query parameters).
	 *
	 * Behaviour:
	 * - Normalise title values:
	 *   /wiki/Foo%20Bar -> /wiki/Foo_Bar
	 * - Normalise empty title:
	 *   /wiki/ -> /wiki/Main
	 *   /w/index.php?title= -> /wiki/Main
	 * - Normalise non-standard title urls:
	 *   /w/index.php?title=Foo_Bar -> /wiki/Foo_Bar
	 * - Don't redirect anything with query parameters other than 'title' or 'action=view'.
	 *
	 * @param Title $title
	 * @return bool True if a redirect was set.
	 * @throws HttpError
	 */
	private function tryNormaliseRedirect( Title $title ) {
		$request = $this->context->getRequest();
		$output = $this->context->getOutput();

		if ( $request->getVal( 'action', 'view' ) != 'view'
			|| $request->wasPosted()
			|| count( $request->getValueNames( array( 'action', 'title' ) ) )
			|| !Hooks::run( 'TestCanonicalRedirect', array( $request, $title, $output ) )
		) {
			return false;
		}

		if ( $title->isSpecialPage() ) {
			list( $name, $subpage ) = SpecialPageFactory::resolveAlias( $title->getDBkey() );
			if ( $name ) {
				$title = SpecialPage::getTitleFor( $name, $subpage );
			}
		}
		// Redirect to canonical url, make it a 301 to allow caching
		$targetUrl = wfExpandUrl( $title->getFullURL(), PROTO_CURRENT );

		if ( $targetUrl != $request->getFullRequestURL() ) {
			$output->setSquidMaxage( 1200 );
			$output->redirect( $targetUrl, '301' );
			return true;
		}

		// If there is no title, or the title is in a non-standard encoding, we demand
		// a redirect. If cgi somehow changed the 'title' query to be non-standard while
		// the url is standard, the server is misconfigured.
		if ( $request->getVal( 'title' ) === null
			|| $title->getPrefixedDBkey() != $request->getVal( 'title' )
		) {
			$message = "Redirect loop detected!\n\n" .
				"This means the wiki got confused about what page was " .
				"requested; this sometimes happens when moving a wiki " .
				"to a new server or changing the server configuration.\n\n";

			if ( $this->config->get( 'UsePathInfo' ) ) {
				$message .= "The wiki is trying to interpret the page " .
					"title from the URL path portion (PATH_INFO), which " .
					"sometimes fails depending on the web server. Try " .
					"setting \"\$wgUsePathInfo = false;\" in your " .
					"LocalSettings.php, or check that \$wgArticlePath " .
					"is correct.";
			} else {
				$message .= "Your web server was detected as possibly not " .
					"supporting URL path components (PATH_INFO) correctly; " .
					"check your LocalSettings.php for a customized " .
					"\$wgArticlePath setting and/or toggle \$wgUsePathInfo " .
					"to true.";
			}
			throw new HttpError( 500, $message );
		}
		return false;
	}

	/**
	 * Initialize the main Article object for "standard" actions (view, etc)
	 * Create an Article object for the page, following redirects if needed.
	 *
	 * @return mixed An Article, or a string to redirect to another URL
	 */
	private function initializeArticle() {

		$title = $this->context->getTitle();
		if ( $this->context->canUseWikiPage() ) {
			// Try to use request context wiki page, as there
			// is already data from db saved in per process
			// cache there from this->getAction() call.
			$page = $this->context->getWikiPage();
			$article = Article::newFromWikiPage( $page, $this->context );
		} else {
			// This case should not happen, but just in case.
			$article = Article::newFromTitle( $title, $this->context );
			$this->context->setWikiPage( $article->getPage() );
		}

		// Skip some unnecessary code if the content model doesn't support redirects
		if ( !ContentHandler::getForTitle( $title )->supportsRedirects() ) {
			return $article;
		}

		$request = $this->context->getRequest();

		// Namespace might change when using redirects
		// Check for redirects ...
		$action = $request->getVal( 'action', 'view' );
		$file = ( $title->getNamespace() == NS_FILE ) ? $article->getFile() : null;
		if ( ( $action == 'view' || $action == 'render' ) // ... for actions that show content
			&& !$request->getVal( 'oldid' ) // ... and are not old revisions
			&& !$request->getVal( 'diff' ) // ... and not when showing diff
			&& $request->getVal( 'redirect' ) != 'no' // ... unless explicitly told not to
			// ... and the article is not a non-redirect image page with associated file
			&& !( is_object( $file ) && $file->exists() && !$file->getRedirected() )
		) {
			// Give extensions a change to ignore/handle redirects as needed
			$ignoreRedirect = $target = false;

			Hooks::run( 'InitializeArticleMaybeRedirect',
				array( &$title, &$request, &$ignoreRedirect, &$target, &$article ) );

			// Follow redirects only for... redirects.
			// If $target is set, then a hook wanted to redirect.
			if ( !$ignoreRedirect && ( $target || $article->isRedirect() ) ) {
				// Is the target already set by an extension?
				$target = $target ? $target : $article->followRedirect();
				if ( is_string( $target ) ) {
					if ( !$this->config->get( 'DisableHardRedirects' ) ) {
						// we'll need to redirect
						return $target;
					}
				}
				if ( is_object( $target ) ) {
					// Rewrite environment to redirected article
					$rarticle = Article::newFromTitle( $target, $this->context );
					$rarticle->loadPageData();
					if ( $rarticle->exists() || ( is_object( $file ) && !$file->isLocal() ) ) {
						$rarticle->setRedirectedFrom( $title );
						$article = $rarticle;
						$this->context->setTitle( $target );
						$this->context->setWikiPage( $article->getPage() );
					}
				}
			} else {
				$this->context->setTitle( $article->getTitle() );
				$this->context->setWikiPage( $article->getPage() );
			}
		}

		return $article;
	}

	/**
	 * Perform one of the "standard" actions
	 *
	 * @param Page $page
	 * @param Title $requestTitle The original title, before any redirects were applied
	 */
	private function performAction( Page $page, Title $requestTitle ) {

		$request = $this->context->getRequest();
		$output = $this->context->getOutput();
		$title = $this->context->getTitle();
		$user = $this->context->getUser();

		if ( !Hooks::run( 'MediaWikiPerformAction',
				array( $output, $page, $title, $user, $request, $this ) )
		) {
			return;
		}

		$act = $this->getAction();

		$action = Action::factory( $act, $page, $this->context );

		if ( $action instanceof Action ) {
			# Let Squid cache things if we can purge them.
			if ( $this->config->get( 'UseSquid' ) &&
				in_array(
					// Use PROTO_INTERNAL because that's what getSquidURLs() uses
					wfExpandUrl( $request->getRequestURL(), PROTO_INTERNAL ),
					$requestTitle->getSquidURLs()
				)
			) {
				$output->setSquidMaxage( $this->config->get( 'SquidMaxage' ) );
			}

			$action->show();
			return;
		}

		if ( Hooks::run( 'UnknownAction', array( $request->getVal( 'action', 'view' ), $page ) ) ) {
			$output->setStatusCode( 404 );
			$output->showErrorPage( 'nosuchaction', 'nosuchactiontext' );
		}

	}

	/**
	 * Run the current MediaWiki instance; index.php just calls this
	 */
	public function run() {
		try {
			try {
				$this->main();
			} catch ( ErrorPageError $e ) {
				// Bug 62091: while exceptions are convenient to bubble up GUI errors,
				// they are not internal application faults. As with normal requests, this
				// should commit, print the output, do deferred updates, jobs, and profiling.
				$this->doPreOutputCommit();
				$e->report(); // display the GUI error
			}
		} catch ( Exception $e ) {
			MWExceptionHandler::handleException( $e );
		}

		$this->doPostOutputShutdown( 'normal' );
	}

	/**
	 * This function commits all DB changes as needed before
	 * the user can receive a response (in case commit fails)
	 *
	 * @since 1.26
	 */
	public function doPreOutputCommit() {
		// Either all DBs should commit or none
		ignore_user_abort( true );

		// Commit all changes and record ChronologyProtector positions
		$factory = wfGetLBFactory();
		$factory->commitMasterChanges();
		$factory->shutdown();

		wfDebug( __METHOD__ . ' completed; all transactions committed' );

		// Set a cookie to tell all CDN edge nodes to "stick" the user to the
		// DC that handles this POST request (e.g. the "master" data center)
		$request = $this->context->getRequest();
		if ( $request->wasPosted() && $factory->hasOrMadeRecentMasterChanges() ) {
			$expires = time() + $this->config->get( 'DataCenterUpdateStickTTL' );
			$request->response()->setCookie( 'UseDC', 'master', $expires );
		}

		// Avoid letting a few seconds of slave lag cause a month of stale data
		if ( $factory->laggedSlaveUsed() ) {
			$maxAge = $this->config->get( 'CdnMaxageLagged' );
			$this->context->getOutput()->lowerCdnMaxage( $maxAge );
			$request->response()->header( "X-Database-Lagged: true" );
			wfDebugLog( 'replication', "Lagged DB used; CDN cache TTL limited to $maxAge seconds" );
		}
	}

	/**
	 * This function does work that can be done *after* the
	 * user gets the HTTP response so they don't block on it
	 *
	 * This manages deferred updates, job insertion,
	 * final commit, and the logging of profiling data
	 *
	 * @param string $mode Use 'fast' to always skip job running
	 * @since 1.26
	 */
	public function doPostOutputShutdown( $mode = 'normal' ) {
		$timing = $this->context->getTiming();
		$timing->mark( 'requestShutdown' );

		// Show visible profiling data if enabled (which cannot be post-send)
		Profiler::instance()->logDataPageOutputOnly();

		$that = $this;
		$callback = function () use ( $that, $mode ) {
			try {
				$that->restInPeace( $mode );
			} catch ( Exception $e ) {
				MWExceptionHandler::handleException( $e );
			}
		};

		// Defer everything else...
		if ( function_exists( 'register_postsend_function' ) ) {
			// https://github.com/facebook/hhvm/issues/1230
			register_postsend_function( $callback );
		} else {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} else {
				// Either all DB and deferred updates should happen or none.
				// The later should not be cancelled due to client disconnect.
				ignore_user_abort( true );
			}

			$callback();
		}
	}

	private function main() {
		global $wgTitle, $wgTrxProfilerLimits;

		$request = $this->context->getRequest();

		// Send Ajax requests to the Ajax dispatcher.
		if ( $this->config->get( 'UseAjax' ) && $request->getVal( 'action' ) === 'ajax' ) {
			// Set a dummy title, because $wgTitle == null might break things
			$title = Title::makeTitle( NS_SPECIAL, 'Badtitle/performing an AJAX call in '
				. __METHOD__
			);
			$this->context->setTitle( $title );
			$wgTitle = $title;

			$dispatcher = new AjaxDispatcher( $this->config );
			$dispatcher->performAction( $this->context->getUser() );
			return;
		}

		// Get title from request parameters,
		// is set on the fly by parseTitle the first time.
		$title = $this->getTitle();
		$action = $this->getAction();
		$wgTitle = $title;

		$trxProfiler = Profiler::instance()->getTransactionProfiler();
		$trxProfiler->setLogger( LoggerFactory::getInstance( 'DBPerformance' ) );

		// Aside from rollback, master queries should not happen on GET requests.
		// Periodic or "in passing" updates on GET should use the job queue.
		if ( !$request->wasPosted()
			&& in_array( $action, array( 'view', 'edit', 'history' ) )
		) {
			$trxProfiler->setExpectations( $wgTrxProfilerLimits['GET'], __METHOD__ );
		} else {
			$trxProfiler->setExpectations( $wgTrxProfilerLimits['POST'], __METHOD__ );
		}

		// If the user has forceHTTPS set to true, or if the user
		// is in a group requiring HTTPS, or if they have the HTTPS
		// preference set, redirect them to HTTPS.
		// Note: Do this after $wgTitle is setup, otherwise the hooks run from
		// isLoggedIn() will do all sorts of weird stuff.
		if (
			$request->getProtocol() == 'http' &&
			(
				$request->getCookie( 'forceHTTPS', '' ) ||
				// check for prefixed version for currently logged in users
				$request->getCookie( 'forceHTTPS' ) ||
				// Avoid checking the user and groups unless it's enabled.
				(
					$this->context->getUser()->isLoggedIn()
					&& $this->context->getUser()->requiresHTTPS()
				)
			)
		) {
			$oldUrl = $request->getFullRequestURL();
			$redirUrl = preg_replace( '#^http://#', 'https://', $oldUrl );

			// ATTENTION: This hook is likely to be removed soon due to overall design of the system.
			if ( Hooks::run( 'BeforeHttpsRedirect', array( $this->context, &$redirUrl ) ) ) {

				if ( $request->wasPosted() ) {
					// This is weird and we'd hope it almost never happens. This
					// means that a POST came in via HTTP and policy requires us
					// redirecting to HTTPS. It's likely such a request is going
					// to fail due to post data being lost, but let's try anyway
					// and just log the instance.

					// @todo FIXME: See if we could issue a 307 or 308 here, need
					// to see how clients (automated & browser) behave when we do
					wfDebugLog( 'RedirectedPosts', "Redirected from HTTP to HTTPS: $oldUrl" );
				}
				// Setup dummy Title, otherwise OutputPage::redirect will fail
				$title = Title::newFromText( 'REDIR', NS_MAIN );
				$this->context->setTitle( $title );
				$output = $this->context->getOutput();
				// Since we only do this redir to change proto, always send a vary header
				$output->addVaryHeader( 'X-Forwarded-Proto' );
				$output->redirect( $redirUrl );
				$output->output();
				return;
			}
		}

		if ( $this->config->get( 'UseFileCache' ) && $title->getNamespace() >= 0 ) {
			if ( HTMLFileCache::useFileCache( $this->context ) ) {
				// Try low-level file cache hit
				$cache = new HTMLFileCache( $title, $action );
				if ( $cache->isCacheGood( /* Assume up to date */ ) ) {
					// Check incoming headers to see if client has this cached
					$timestamp = $cache->cacheTimestamp();
					if ( !$this->context->getOutput()->checkLastModified( $timestamp ) ) {
						$cache->loadFromFileCache( $this->context );
					}
					// Do any stats increment/watchlist stuff
					// Assume we're viewing the latest revision (this should always be the case with file cache)
					$this->context->getWikiPage()->doViewUpdates( $this->context->getUser() );
					// Tell OutputPage that output is taken care of
					$this->context->getOutput()->disable();
					return;
				}
			}
		}

		// Actually do the work of the request and build up any output
		$this->performRequest();

		// Now commit any transactions, so that unreported errors after
		// output() don't roll back the whole DB transaction and so that
		// we avoid having both success and error text in the response
		$this->doPreOutputCommit();

		// Output everything!
		$this->context->getOutput()->output();
	}

	/**
	 * Ends this task peacefully
	 * @param string $mode Use 'fast' to always skip job running
	 */
	public function restInPeace( $mode = 'fast' ) {
		// Assure deferred updates are not in the main transaction
		wfGetLBFactory()->commitMasterChanges();

		// Ignore things like master queries/connections on GET requests
		// as long as they are in deferred updates (which catch errors).
		Profiler::instance()->getTransactionProfiler()->resetExpectations();

		// Do any deferred jobs
		DeferredUpdates::doUpdates( 'enqueue' );

		// Make sure any lazy jobs are pushed
		JobQueueGroup::pushLazyJobs();

		// Now that everything specific to this request is done,
		// try to occasionally run jobs (if enabled) from the queues
		if ( $mode === 'normal' ) {
			$this->triggerJobs();
		}

		// Log profiling data, e.g. in the database or UDP
		wfLogProfilingData();

		// Commit and close up!
		$factory = wfGetLBFactory();
		$factory->commitMasterChanges();
		$factory->shutdown();

		wfDebug( "Request ended normally\n" );
	}

	/**
	 * Potentially open a socket and sent an HTTP request back to the server
	 * to run a specified number of jobs. This registers a callback to cleanup
	 * the socket once it's done.
	 */
	public function triggerJobs() {
		$jobRunRate = $this->config->get( 'JobRunRate' );
		if ( $jobRunRate <= 0 || wfReadOnly() ) {
			return;
		} elseif ( $this->getTitle()->isSpecial( 'RunJobs' ) ) {
			return; // recursion guard
		}

		if ( $jobRunRate < 1 ) {
			$max = mt_getrandmax();
			if ( mt_rand( 0, $max ) > $max * $jobRunRate ) {
				return; // the higher the job run rate, the less likely we return here
			}
			$n = 1;
		} else {
			$n = intval( $jobRunRate );
		}

		$runJobsLogger = LoggerFactory::getInstance( 'runJobs' );

		if ( !$this->config->get( 'RunJobsAsync' ) ) {
			// Fall back to running the job here while the user waits
			$runner = new JobRunner( $runJobsLogger );
			$runner->run( array( 'maxJobs'  => $n ) );
			return;
		}

		try {
			if ( !JobQueueGroup::singleton()->queuesHaveJobs( JobQueueGroup::TYPE_DEFAULT ) ) {
				return; // do not send request if there are probably no jobs
			}
		} catch ( JobQueueError $e ) {
			MWExceptionHandler::logException( $e );
			return; // do not make the site unavailable
		}

		$query = array( 'title' => 'Special:RunJobs',
			'tasks' => 'jobs', 'maxjobs' => $n, 'sigexpiry' => time() + 5 );
		$query['signature'] = SpecialRunJobs::getQuerySignature(
			$query, $this->config->get( 'SecretKey' ) );

		$errno = $errstr = null;
		$info = wfParseUrl( $this->config->get( 'Server' ) );
		MediaWiki\suppressWarnings();
		$sock = fsockopen(
			$info['host'],
			isset( $info['port'] ) ? $info['port'] : 80,
			$errno,
			$errstr,
			// If it takes more than 100ms to connect to ourselves there
			// is a problem elsewhere.
			0.1
		);
		MediaWiki\restoreWarnings();
		if ( !$sock ) {
			$runJobsLogger->error( "Failed to start cron API (socket error $errno): $errstr" );
			// Fall back to running the job here while the user waits
			$runner = new JobRunner( $runJobsLogger );
			$runner->run( array( 'maxJobs'  => $n ) );
			return;
		}

		$url = wfAppendQuery( wfScript( 'index' ), $query );
		$req = (
			"POST $url HTTP/1.1\r\n" .
			"Host: {$info['host']}\r\n" .
			"Connection: Close\r\n" .
			"Content-Length: 0\r\n\r\n"
		);

		$runJobsLogger->info( "Running $n job(s) via '$url'" );
		// Send a cron API request to be performed in the background.
		// Give up if this takes too long to send (which should be rare).
		stream_set_timeout( $sock, 1 );
		$bytes = fwrite( $sock, $req );
		if ( $bytes !== strlen( $req ) ) {
			$runJobsLogger->error( "Failed to start cron API (socket write error)" );
		} else {
			// Do not wait for the response (the script should handle client aborts).
			// Make sure that we don't close before that script reaches ignore_user_abort().
			$status = fgets( $sock );
			if ( !preg_match( '#^HTTP/\d\.\d 202 #', $status ) ) {
				$runJobsLogger->error( "Failed to start cron API: received '$status'" );
			}
		}
		fclose( $sock );
	}
}
