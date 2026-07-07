<?php

namespace Plugins\SiteSEO;


/**
 * RobotsTxtEditor: A class for parsing, updating, and writing robots.txt files.
 *
 * This class provides methods to read, parse, update, and write robots.txt files,
 * allowing for fine-grained control over crawling rules for search engine bots.
 *
 * @author Hakeem Shamavu (hakimushamavu@gmail.com)
 * @link https://developers.google.com/webmasters/control-crawl-index/docs/robots_txt
 * @link https://help.yandex.com/webmaster/controlling-robot/robots-txt.xml
 */

class RobotsTxtEditor
{
    // default encoding
    const DEFAULT_ENCODING = 'UTF-8';

    // directives
    const DIRECTIVE_NOINDEX = 'Noindex';
    const DIRECTIVE_ALLOW = 'Allow';
    const DIRECTIVE_DISALLOW = 'Disallow';
    const DIRECTIVE_HOST = 'Host';
    const DIRECTIVE_SITEMAP = 'Sitemap';
    const DIRECTIVE_USERAGENT = 'User-agent';
    const DIRECTIVE_CRAWL_DELAY = 'Crawl-delay';
    const DIRECTIVE_CLEAN_PARAM = 'Clean-param';

    //default user-agent
    const USER_AGENT_ALL = '*';
    const CRAWL_DELAY_SECONDS = 10;
    protected $filePath;
    protected $encoding;

    /**
     * @var string $content Original robots.txt content
     */
    protected $contents = '';

    /**
     * @var array $rules Rules with all parsed directives by all user-agents
     */
    protected $rules = [];

    protected $reading = false;


    /**
     * Constructor.
     *
     * Initializes the RobotsTxtEditor object.
     *
     * @param string $encoding The character encoding of the robots.txt file (default is UTF-8).
     */
    public function __construct($encoding = self::DEFAULT_ENCODING)
    {
        $this->filePath = ABSPATH . 'robots.txt';
        $this->encoding = $encoding;

        $this_ = $this;
        execute_with_temp_permission(ABSPATH,function() use ($this_){
            // Ensure the robots.txt file exists
            if (!file_exists($this_->filePath)) {
                $this_->createDefaultRobotsTxt();
            }
        });
        
    }

    /**
     * Create a default robots.txt file with a basic "allow all" rule.
     *
     * This method creates a default robots.txt file with an allow-all rule
     * for all user agents, along with a reference to the XML sitemap and host URL.
     */
    private function createDefaultRobotsTxt()
    {
        check_loader_function_and_include_file('is_site_installed', 'Install.IS_Installation');
        if (is_site_installed()) {

            $default_rules = generateRobotsTxtContentArray();

            $this->contents = "";
            $this->reading = true;
            $robots_generate = get_option('robots_generate', []);
            check_loader_function_and_include_file('current_time', 'Utility.Date');
            $robots_generate['created_at'] = current_time('mysql', 1);
            update_option('robots_generate', $robots_generate);


            $this->updateRobotsTxt($default_rules);
        }
    }

    /**
     * Get rules by specific bot (user-agent)
     * Use $userAgent = NULL to get all rules for all user-agents grouped by user-agent. User-agents will return in lower case.
     * Use $userAgent = '*' to get common rules.
     * Use $userAgent = 'YandexBot' to get rules for user-agent 'YandexBot'.
     *
     * @param string $userAgent
     * @return array
     */
    public function getRules($userAgent = null)
    {
        if (is_null($userAgent)) {
            //return all rules
            return $this->rules;
        } else {
            if (isset($this->rules[$userAgent])) {
                return $this->rules[$userAgent];
            } else {
                return [];
            }
        }
    }

    /**
     * Get sitemaps links.
     * Sitemap always relates to all user-agents and return in rules with user-agent "*"
     *
     * @return array
     */
    public function getSitemaps()
    {
        $rules = $this->getRules(self::USER_AGENT_ALL);
        if (!empty($rules[self::DIRECTIVE_SITEMAP])) {
            return $rules[self::DIRECTIVE_SITEMAP];
        }

        return [];
    }

    /**
     * Return original robots.txt content
     *
     * @return string
     */
    public function getContent()
    {
        return $this->contents;
    }

    /**
     * Read and parse the contents of the robots.txt file.
     *
     * @return array An associative array representing the parsed robots.txt file.
     *
     * This method reads the contents of the robots.txt file, parses it into
     * an associative array of directives, and returns the result.
     */
    public function readRobotsTxt()
    {
        $this->reading = true;
        if (empty($this->contents)) {
            // Read the contents of the robots.txt file
            $this->contents = file_get_contents($this->filePath);
        }

        if (empty($this->contents)) return [];

        // Parse the robots.txt content into an associative array
        $lines = explode("\n", $this->contents);
        $parsedRobotsTxt = [];
        $currentUserAgent = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            if (strpos($line, self::DIRECTIVE_USERAGENT . ':') === 0) {
                $currentUserAgent = substr($line, strlen(self::DIRECTIVE_USERAGENT . ':'));
                $parsedRobotsTxt[$currentUserAgent] = [
                    self::DIRECTIVE_CRAWL_DELAY => $currentUserAgent == self::USER_AGENT_ALL ? self::CRAWL_DELAY_SECONDS : 5
                ];
            } else {

                $directive = $this->getDerictiveOnLine($line);
                if ($directive) {
                    $agents = $parsedRobotsTxt[$currentUserAgent];
                    if (!isset($agents[$directive])) {
                        $agents[$directive] = [];
                    }
                    $agents[$directive][] = substr($line, strlen($directive . ':'));
                    $parsedRobotsTxt[$currentUserAgent] = $agents;
                }
            }
        }

        $this->rules = $parsedRobotsTxt;
        return $this->rules;
    }

    /**
     * Return array of supported directives
     *
     * @return array
     */
    protected function getAllowedDirectives()
    {
        return [
            self::DIRECTIVE_NOINDEX,
            self::DIRECTIVE_ALLOW,
            self::DIRECTIVE_DISALLOW,
            self::DIRECTIVE_HOST,
            self::DIRECTIVE_SITEMAP,
            self::DIRECTIVE_USERAGENT,
            self::DIRECTIVE_CRAWL_DELAY,
            self::DIRECTIVE_CLEAN_PARAM,
        ];
    }

    protected function getDerictiveOnLine(string $line): string|false
    {
        $directives = $this->getAllowedDirectives();
        foreach ($directives as $directive) {
            if (strpos($line, $directive . ':') === 0) {
                return $directive;
            }
        }

        return false;
    }

    /**
     * Update the robots.txt file with new rules.
     *
     * @param array $rules Associative array of rules to update the robots.txt file.
     *
     * This method updates the robots.txt file with new rules provided in the $rules parameter.
     * It merges and updates existing rules with the new ones, ensuring consistency and accuracy.
     */
    public function updateRobotsTxt($rules = [])
    {

        // Ensure robots.txt file is read
        if (!$this->reading) {
            $this->readRobotsTxt();
        }


        foreach ($rules as $user_agent => $value) {
            if (isset($this->rules[$user_agent])) {
                // Merge new directives with existing ones
                $directives = (array) $this->rules[$user_agent];
            } else {
                $directives = [];
            }

            // Merge or update disallowed paths
            if (isset($value[self::DIRECTIVE_DISALLOW])) {
                $allowed = $directives[self::DIRECTIVE_ALLOW]??[];
                if (!empty($allowed)) {
                    check_function_and_include_file('removeArray', 'Utility.array');

                    $removed_directives = apply_filters('site_seo_robots_' . strtolower(self::DIRECTIVE_DISALLOW), $value[self::DIRECTIVE_DISALLOW]??[], $user_agent);

                    removeArrays($allowed, $removed_directives);

                    do_action('site_seo_robots_done_' . strtolower(self::DIRECTIVE_DISALLOW), $removed_directives, $user_agent);
                }

                $directives[self::DIRECTIVE_ALLOW] = $allowed;

                check_function_and_include_file('array_merge_unique', 'Utility.array');
                $directives[self::DIRECTIVE_DISALLOW] = array_merge_unique($directives[self::DIRECTIVE_DISALLOW]??[], $value[self::DIRECTIVE_DISALLOW]??[]);
            }
            // Merge or update allowed paths
            if (isset($value[self::DIRECTIVE_ALLOW])) {
                $disallowed = $directives[self::DIRECTIVE_DISALLOW]??[];
                if (!empty($disallowed)) {
                    check_function_and_include_file('removeArray', 'Utility.array');
                    $added_directives = apply_filters('site_seo_robots_' . strtolower(self::DIRECTIVE_ALLOW), $value[self::DIRECTIVE_ALLOW]??[], $user_agent);

                    removeArrays($disallowed, $value[self::DIRECTIVE_ALLOW]);
                    do_action('site_seo_robots_done_' . strtolower(self::DIRECTIVE_ALLOW), $added_directives, $user_agent);
                }

                $directives[self::DIRECTIVE_DISALLOW] = $disallowed;

                check_function_and_include_file('array_merge_unique', 'Utility.array');
                $directives[self::DIRECTIVE_ALLOW] = array_merge_unique($directives[self::DIRECTIVE_ALLOW], $value[self::DIRECTIVE_ALLOW]??[]);
            }

            // Merge or update other directives
            foreach ([self::DIRECTIVE_HOST, self::DIRECTIVE_SITEMAP, self::DIRECTIVE_CRAWL_DELAY, self::DIRECTIVE_CLEAN_PARAM] as $directive) {
                if (isset($value[$directive])) {
                    check_function_and_include_file('array_merge_unique', 'Utility.array');

                    $directives[$directive] = array_merge_unique(
                        $directives[$directive] ?? [],
                        (array) $value[$directive]
                    );
                    do_action('site_seo_robots_done_' . strtolower($directive), $directives[$directive]??[], $user_agent);
                }
            }

            $this->rules[$user_agent] = $directives;
        }



        // Initialize new content for robots.txt
        $newContent = '';

        // Get allowed directives
        $allowedDirectives = $this->getAllowedDirectives();

        // Iterate through rules
        foreach ($this->rules as $userAgent => $directives) {
            // Append user agent directive
            $newContent .= "\n".self::DIRECTIVE_USERAGENT . ": $userAgent\n";

            // Filter directives to include only allowed ones
            $filteredDirectives = array_intersect_key($directives, array_flip($allowedDirectives));

            // Iterate through filtered directives
            foreach ($filteredDirectives as $directive => $values) {
                // Convert values to array
                $values = (array) $values;

                // Append directive and values to new content
                $newContent .= implode("", array_map(function ($value) use ($directive) {
                    return "$directive: $value\n";
                }, $values));
            }
        }

        $robots_generate = get_option('robots_generate', []);

        $robots_generate['status'] = true;
        $robots_generate['count'] = count($this->rules);
        $robots_generate['rules'] = $this->rules;

        check_loader_function_and_include_file('current_time', 'Utility.Date');
        $robots_generate['update_at'] = current_time('mysql', 1);

        update_option('robots_generate', $robots_generate);

        $this->contents = $newContent;
        // Write the new content to the robots.txt file
        file_put_contents($this->filePath, $newContent);
    }

    /**
     * Add rules for a specific user agent to the robots.txt file.
     *
     * @param string $userAgent The user agent for which to add rules.
     * @param array $rules An associative array of rules to add for the specified user agent.
     *
     * This method adds rules for a specific user agent to the robots.txt file.
     * It allows for fine-grained control over crawling behavior for different bots.
     */
    public function addRulesForUserAgent($userAgent, $rules = [])
    {


        $rules_ = [];
        // Set allow rules for specified user agent
        if (empty($rules)) {
            $rules_[$userAgent] = [
                self::DIRECTIVE_DISALLOW => [],
                self::DIRECTIVE_CRAWL_DELAY => $userAgent == self::USER_AGENT_ALL ? self::CRAWL_DELAY_SECONDS : 5
            ];
        } else {
            $rules[self::DIRECTIVE_CRAWL_DELAY] = $userAgent == self::USER_AGENT_ALL ? self::CRAWL_DELAY_SECONDS : 5;
            $rules_[$userAgent] = $rules;
        }

        // Update robots.txt with new rules
        $this->updateRobotsTxt($rules_);
    }
}
