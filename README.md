# Decoupled Drupal Project

A Drupal 11 installation featuring the `dc_core` installation profile, designed for headless/decoupled applications. This project can be used standalone or as part of the [decoupled.io](https://decoupled.io) platform.

## Features

- **Drupal 11** with modern PHP 8.3
- **dc_core installation profile** - pre-configured for headless/decoupled architecture
- **Custom modules** for multisite management and API functionality
- **Docker support** for local development and production
- **GraphQL API** ready for frontend consumption

## Getting Started

### 1. Make Changes

Edit Drupal code, modules, themes, or configuration:

```bash
# Make your changes to Drupal code, modules, themes, etc.
```

### 2. Commit and Push

```bash
git add .
git commit -m "Your commit message"
git push
```

## Using with decoupled.io

If you're using this project with the decoupled.io platform, changes are automatically deployed via GitHub Actions and Ansible. See the [decoupled.io documentation](https://github.com/nextagencyio/decoupled-dashboard) for deployment workflows.

## Repository Structure

```
web/
├── profiles/
│   └── dc_core/          # Installation profile
│       ├── modules/      # Custom modules
│       ├── themes/       # Custom themes
│       └── dc_core.info.yml
├── sites/
│   └── default/
│       └── settings.php
└── ...

docker-compose.prod.yml    # Docker configuration
```

## Installation Profile: dc_core

The `dc_core` installation profile provides:

- Pre-configured content types and fields
- GraphQL API endpoints
- Multisite management capabilities
- Optimized for headless/decoupled architecture
- Custom modules for common decoupled use cases

## Requirements

- PHP 8.3+
- Drupal 11
- Composer
- Docker (optional, for containerized deployment)

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

## License

This project is licensed under GPL-2.0-or-later, consistent with Drupal core.
