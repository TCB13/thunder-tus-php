# ThunderTUS PHP

Resumable file upload in PHP using tus resumable upload protocol v1.0.0.

**tus** is a HTTP based protocol for resumable file uploads. Resumable means you can carry on where you left off without re-uploading whole data again in case of any interruptions. An interruption may happen willingly if the user wants to pause, or by accident in case of a network issue or server outage.

**thunder tus** is the most reliable implementation of the tus protocol for PHP yet. Designed for **high concurrency** (real world scenarios) and integration simplicity it's **free of external dependencies** (complex caching engines etc.). It is also **PSR-7 compliant** in order to bring the tus protocol to modern PHP frameworks such as **Slim 3**.

**extensions**: building on the extensibility capabilities of the tus protocol, thunder tus also includes two new extensions:

- **CrossCheck**: final checksum of the uploaded files to ensure maximum reliability;
- **Express**: tus uploads with a single HTTP call - making tus better suited for mobile contexts and other environments where performance is a priority.

### Installation

Pull the package via composer.
```shell
$ composer require TCB13/thunder-tus-php
```

### Usage

Check out the `examples` directory.