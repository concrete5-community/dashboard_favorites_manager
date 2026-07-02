[![Tests](https://github.com/concrete5-community/dashboard_favorites_manager/actions/workflows/tests.yml/badge.svg)](https://github.com/concrete5-community/dashboard_favorites_manager/actions/workflows/tests.yml)

# Dashboard Favorites Manager: the Concrete CMS missing feature!!

Dashboard Favorites Manager helps Concrete CMS users manage the existing dashboard favorites menu, which is not directly editable in the default interface.

The package adds a dedicated dashboard page where users can add, remove, reorder, import, and export their favorite dashboard links without manually editing user data.

It also **adds an optional star button** to the Concrete CMS toolbar. This button opens a quick menu with the current user's dashboard favorites, making frequently used dashboard pages easier to reach.

Favorites can be reordered with **drag and drop** in table, or with simple up and down controls on smaller screens. A real-time search feature makes it easy to find dashboard pages and add them to favorites in one single operation.

The toolbar menu shows the current user's favorites, can search dashboard pages, and can optionally show the Concrete CMS version, "Clear cache now!", and "Log out".

All settings are saved **per user.**

## Installation

This package requires Concrete CMS 9.2.0 or later.

There are multiple ways to install this package.

### With the Concrete CMS Marketplace

See the [marketplace page](https://market.concretecms.com/products/dashboard-favorites-manager/44e04e58-6579-11f1-b89e-0e1cf28cdc53) of this package.

### With composer

1. run
   ```sh
   composer require concrete5-community/dashboard_favorites_manager
   ```
2. In the Concrete CMS dashboard, go to System & Settings > Extend Concrete > Add Functionality
3. Find Dashboard Favorites Manager in the list of available packages and click Install.

### Manually

1. Go to the [releases page](https://github.com/concrete5-community/dashboard_favorites_manager/releases) page
2. download the `dashboard_favorites_manager-vX.Y.Z.zip` file attached to the release
3. extract it into the `packages` directory of your Concrete CMS installation
4. In the Concrete CMS dashboard, go to System & Settings > Extend Concrete > Add Functionality
5. Find Dashboard Favorites Manager in the list of available packages and click Install.

## Usage

After installation, open Dashboard > Welcome > Dashboard Favorites Manager to manage your dashboard favorites and toolbar options.

## Features

- Dedicated dashboard page to manage Concrete CMS dashboard favorites
- Add or remove dashboard pages from favorites
- Reorder favorites with drag and drop on desktop
- Reorder favorites with up/down controls on smaller screens
- Search dashboard pages before adding them
- Import favorites from JSON
- Export favorites to JSON
- Import report showing imported, existing, and unavailable items
- Optional toolbar star button for quick favorites access
- Optional dashboard page search inside the toolbar favorites menu
- Add or remove dashboard pages from favorites directly from toolbar search results
- Optional Concrete CMS version label inside the toolbar
- Optional "Clear cache now!" action inside the toolbar menu
- Optional "Log out" action inside the toolbar menu
- Per-user settings and favorites
- Automatically repairs malformed dashboard favorite data where possible
- Safe dashboard-only favorite links in the toolbar menu

## License

This package is licensed under the GNU General Public License v2.0 only.
See the LICENSE file for details.
