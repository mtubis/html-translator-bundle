# Flex recipe

`mtubis/html-translator-bundle/0.1/` is the recipe for [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib):
it enables the bundle, copies `config/packages/html_translator.yaml` (OpenAI by default, the offline `pseudo` driver in
`when@test`) and adds the two env variables of that configuration to `.env`.

It is submitted after a release (see [RELEASING.md](../RELEASING.md)): copy the directory to a fork of
recipes-contrib as `mtubis/html-translator-bundle/0.1/` and open a pull request; the recipes-contrib CI validates the
manifest and runs `composer require` with the recipe. `tests/Unit/RecipeTest.php` keeps the recipe's configuration
valid for the bundle meanwhile.
