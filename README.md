
# Pico Environment Variable In Configuration

Replace environment variable placeholders in configuration with their values.
Use the specified syntax as value in your configuration YAML to get them replaced at runtime.

Uses Symfony's [environment variable processor syntax](https://symfony.com/doc/5.2/configuration/env_var_processors.html), with all its features.

There is one notable deviation from Symfony's syntax : in `env(default:fallback_param:BAR)`, `fallback_param` is not the name of a parameter whose vaalue should be used by default, but rather the default value itself.

## Example

With this plugin, you can have the following `config.yml`: 
```yaml
##
# Basic
#
site_title: "My website"            # The title of your website
base_url: ~                         # Pico will try to guess its base URL, if this fails, override it here
                                    #     Example: http://example.com/pico/
rewrite_url: true                   # A boolean (true or false) indicating whether URL rewriting is forced
timezone: '%env(TZ)%'               # Your PHP installation might require you to manually specify a timezone

##
# Theme
#
theme: "mytheme"                                       # The name of your custom theme
theme_url: ~                                           # Pico will try to guess the URL to the themes dir of your installation
                                                       #     If this fails, override it here. Example: http://example.com/pico/themes/
theme_config:
    widescreen: false                                  # Default theme: Use more horicontal space (i.e. make the site container wider)
twig_config:
    cache: '%env(default:true:TWIG_CACHE)%'            # Enable Twig template caching by specifying a path to a writable directory
    autoescape: '%env(default:true:TWIG_AUTOESCAPE)%'  # Let Twig escape variables by default
    debug: '%env(default:false:TWIG_DEBUG)%'           # Enable Twig's debugging mode
```

Assuming you have the following environment variables:
```ini
TZ=America/Los_Angeles
TWIG_DEBUG=true
TWIG_CACHE=false
```

The plugin will transform Pico's parsed configuration to:
```
array(8) {
  ["site_title"]=>
  string(10) "My website"
  ["base_url"]=>
  NULL
  ["rewrite_url"]=>
  bool(true)
  ["timezone"]=>
  string(19) "America/Los_Angeles"
  ["theme"]=>
  string(7) "mytheme"
  ["theme_url"]=>
  NULL
  ["theme_config"]=>
  array(1) {
    ["widescreen"]=>
    bool(false)
  }
  ["twig_config"]=>
  array(3) {
    ["cache"]=>
    string(5) "false"
    ["autoescape"]=>
    string(4) "true"
    ["debug"]=>
    string(4) "true"
  }
}
```

## License

This plugin is released under the MIT license.

Parsing code is extracted from Symfony's Dependency Injection EnvVarProcessor and adapted to work standalone. Symfony's Dependency Injection is under MIT license.
