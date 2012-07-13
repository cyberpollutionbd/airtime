# -*- coding: utf-8 -*-
import pyinotify
from pydispatch import dispatcher

import media.monitor.pure as mmp
from media.monitor.pure import IncludeOnly
from media.monitor.events import OrganizeFile, NewFile, DeleteFile


class BaseListener(object):
    def my_init(self, signal):
        self.signal = signal

class OrganizeListener(BaseListener, pyinotify.ProcessEvent):
    # this class still don't handle the case where a dir was copied recursively

    def process_IN_CLOSE_WRITE(self, event): self.process_to_organize(event)
    # got cookie
    def process_IN_MOVED_TO(self, event): self.process_to_organize(event)

    @IncludeOnly(mmp.supported_extensions)
    def process_to_organize(self, event):
        dispatcher.send(signal=self.signal, sender=self, event=OrganizeFile(event))

class StoreWatchListener(BaseListener, pyinotify.ProcessEvent):

    def process_IN_CLOSE_WRITE(self, event): self.process_create(event)
    def process_IN_MOVED_TO(self, event): self.process_create(event)
    def process_IN_MOVED_FROM(self, event): self.process_delete(event)
    def process_IN_DELETE(self,event): self.process_delete(event)

    @IncludeOnly(mmp.supported_extensions)
    def process_create(self, event):
        dispatcher.send(signal=self.signal, sender=self, event=NewFile(event))

    @IncludeOnly(mmp.supported_extensions)
    def process_delete(self, event):
        dispatcher.send(signal=self.signal, sender=self, event=DeleteFile(event))

