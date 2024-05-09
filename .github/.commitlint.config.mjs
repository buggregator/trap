// More info: https://github.com/wayofdev/npm-shareable-configs/blob/master/packages/commitlint-config/src/index.js
const automaticCommitPattern = /^chore\(release\):.*\[skip ci]/

export default {
    extends: ['@commitlint/config-conventional'],
    /*
      This resolves a linting conflict between commitlint's body-max-line-length
      due to @semantic-release/git putting release notes in the commit body
      https://github.com/semantic-release/git/issues/331
    */
    ignores: [(commitMessage) => automaticCommitPattern.test(commitMessage)],
    rules: {
        'body-leading-blank': [1, 'always'],
        'body-max-line-length': [2, 'always', 120],
        'footer-leading-blank': [1, 'always'],
        'footer-max-line-length': [2, 'always', 120],
        'header-max-length': [2, 'always', 100],
        'scope-case': [2, 'always', 'lower-case'],
        'subject-case': [2, 'never', ['sentence-case', 'start-case', 'pascal-case', 'upper-case']],
        'subject-empty': [2, 'never'],
        'subject-full-stop': [2, 'never', '.'],
        'type-case': [2, 'always', 'lower-case'],
        'type-empty': [2, 'never'],
        'type-enum': [
            2,
            'always',
            [
                'feat',     // New feature
                'fix',      // Bug fix
                'perf',     // Performance improvement
                'docs',     // Documentation changes
                'style',    // Code style update (formatting, missing semi colons, etc)
                'deps',     // Dependency updates
                'refactor', // Code refactoring
                'ci',       // Continuous integration changes
                'test',     // Adding missing tests
                'revert',   // Revert to a previous commit
                'build',    // Changes that affect the build system
                'chore',    // Other changes that don't modify src or test files
                'security', // Security improvements
            ],
        ],
    },
}
