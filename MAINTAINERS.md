# Publishing a new version

Application fixes and version bumps originate on GitLab `master`. Port the relevant commits to GitHub `main` with `git cherry-pick -x`, preserving the CI configuration of each repository.

1. Update `$pkgVersion` in `controller.php` on GitLab `master` and commit the fixes and version bump.
2. Run Concrete's PHP CS Fixer in dry-run mode and the regression scripts under `.tests` before pushing.
3. Push GitLab `master`. Its pipeline must pass coding style and regressions before building the package and publishing the `v`-prefixed release tag.
4. Cherry-pick the application fixes and version commit onto GitHub `main`, then push **only `main`**.
5. Wait for the **Tests** workflow to succeed on that exact commit, including coding style, PHP syntax and all Concrete installation jobs.
6. Create an annotated GitHub tag matching `$pkgVersion`, without a `v` prefix, and push the tag separately. Never push the branch and release tag together.
7. The [release workflow](https://github.com/concrete5-community/dashboard_favorites_manager/actions/workflows/create-release.yml) checks successful tests for the tagged commit before publishing the release and distribution ZIP. A missing, pending or failed test run blocks publication.

Keep already published tags unchanged; publish a new patch version for subsequent corrections.
