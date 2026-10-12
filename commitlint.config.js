module.exports = {
	extends: [ './node_modules/newspack-scripts/config/commitlint.config.js' ],
	rules: {
		// One line of at most 72 characters, lower-case subject, known scopes.
		'header-max-length': [ 2, 'always', 72 ],
		'subject-case': [ 2, 'always', 'lower-case' ],
		'scope-enum': [
			2,
			'always',
			[
				'core',
				'data',
				'rest',
				'render',
				'console',
				'editor',
				'front',
				'questions',
				'ads',
				'seo',
				'authors',
				'cli',
				'a11y',
				'i18n',
				'e2e',
				'tests',
				'build',
				'deps',
				'docs',
				'release',
			],
		],
	},
};
