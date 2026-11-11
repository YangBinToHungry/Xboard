import argparse
import pathlib
import tempfile
import subprocess

parser = argparse.ArgumentParser(description="Check container startup without a production database")
parser.add_argument("--image", default="alpine:3.21")
args = parser.parse_args()
entry=pathlib.Path(__file__).resolve().parents[1] / '.docker/entrypoint.sh'
with tempfile.TemporaryDirectory(dir='/tmp') as root:
 r=pathlib.Path(root);(r/'.env').write_text('INSTALLED=true\n');(r/'php').write_text('#!/bin/sh\necho PHP_COMMAND:$@\nexit "${SHIM_EXIT:-0}"\n');(r/'php').chmod(0o755)
 for auto,code,expect,command in [('false','0',0,'xboard:check-migrations'),('false','17',17,'xboard:check-migrations'),('true','17',17,'xboard:update')]:
  p=subprocess.run(['docker','run','--rm','-v',str(entry)+':/entrypoint.sh:ro','-v',str(r/'.env')+':/www/.env:ro','-v',str(r)+':/shim:ro','-e','PATH=/shim:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','-e','XBOARD_AUTO_UPDATE='+auto,'-e','SHIM_EXIT='+code,args.image,'sh','/entrypoint.sh','sh','-c','echo BOOTED'],capture_output=True,text=True)
  assert p.returncode==expect,(p.returncode,p.stderr)
  assert command in p.stdout
  assert ('BOOTED' in p.stdout)==(expect==0)
 print('Entrypoint checks passed: read-only preflight, pending-schema stop, explicit-update failure stop')
